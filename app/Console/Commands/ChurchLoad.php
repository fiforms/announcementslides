<?php

namespace App\Console\Commands;

use App\Models\AdventistEntity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;

class ChurchLoad extends Command
{
    protected $signature = 'church:load
                            {--conference= : Conference code (e.g. CRLC)}
                            {--delay=500 : Milliseconds to wait between requests}
                            {--fresh : Delete existing records for this conference before loading}
                            {--debug : Dump parsed data and confirm before each entity page load}';

    protected $description = 'Load Adventist entities for a conference from adventistdirectory.org';

    private const BASE_URL = 'https://www.adventistdirectory.org';

    public function handle(): int
    {
        $conference = $this->option('conference') ?? $this->ask('Conference code (e.g. CRLC)');

        if (! $conference) {
            $this->error('Conference code is required.');
            return self::FAILURE;
        }

        $conference = strtoupper(trim($conference));

        if ($this->option('fresh')) {
            $deleted = AdventistEntity::where('conference_code', $conference)->delete();
            $this->line("Deleted {$deleted} existing records for {$conference}.");
        }

        $this->info("Fetching entity list for conference: {$conference}");

        $entityIds = $this->fetchEntityIds($conference);

        if (empty($entityIds)) {
            $this->warn('No entity links found on the search results page.');
            return self::FAILURE;
        }

        $this->info('Found ' . count($entityIds) . ' entities. Loading details...');

        $debug = (bool) $this->option('debug');
        $saved = 0;
        $delay = (int) $this->option('delay');

        $bar = $debug ? null : $this->output->createProgressBar(count($entityIds));
        $bar?->start();

        foreach ($entityIds as $entityId) {
            $url = self::BASE_URL . '/ViewEntity.aspx?EntityID=' . $entityId;

            if ($debug && ! $this->confirm("  Load entity {$entityId}? ({$url})", true)) {
                $this->line("  Skipped {$entityId}.");
                continue;
            }

            $data = $this->fetchEntityData($entityId, $conference);

            if ($data) {
                if ($debug) {
                    $this->newLine();
                    $this->line("<fg=cyan>--- Entity {$entityId} ---</>");
                    $this->table(
                        ['Field', 'Value'],
                        collect($data)
                            ->except('extra')
                            ->map(fn ($v, $k) => [$k, is_null($v) ? '<fg=gray>null</>' : $v])
                            ->values()
                            ->toArray()
                    );
                    if (! empty($data['extra'])) {
                        $this->line('<fg=yellow>extra:</>');
                        $this->table(
                            ['Key', 'Value'],
                            collect($data['extra'])->map(fn ($v, $k) => [$k, $v])->values()->toArray()
                        );
                    }
                    if (! $this->confirm('  Save this record?', true)) {
                        $this->line('  Skipped.');
                        continue;
                    }
                }

                AdventistEntity::updateOrCreate(
                    ['conference_code' => $conference, 'om_entity_id' => $entityId],
                    $data
                );
                $saved++;
            }

            $bar?->advance();

            if ($delay > 0) {
                usleep($delay * 1000);
            }
        }

        $bar?->finish();
        $this->newLine();
        $this->info("Done. Saved {$saved} of " . count($entityIds) . " entities.");

        return self::SUCCESS;
    }

    private function fetchEntityIds(string $conference): array
    {
        $ids      = [];
        $page     = 1;

        while (true) {
            $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; church-loader/1.0)'])
                ->timeout(30)
                ->get(self::BASE_URL . '/SearchResults.aspx', [
                    'AdmFieldID' => $conference,
                    'PageIndex'  => $page,
                ]);

            if (! $response->successful()) {
                $this->error("Failed to fetch search results page {$page} (HTTP {$response->status()}).");
                break;
            }

            $before  = count($ids);
            $crawler = new Crawler($response->body());

            $crawler->filterXPath('//a[contains(@href, "ViewEntity.aspx?EntityID=")]')->each(function (Crawler $node) use (&$ids) {
                $href = $node->attr('href');
                if (preg_match('/EntityID=(\d+)/i', $href, $m)) {
                    $ids[$m[1]] = $m[1];
                }
            });

            $this->line("  Page {$page}: found " . (count($ids) - $before) . " new entities (total " . count($ids) . ").");

            // Stop when the page added nothing new
            if (count($ids) === $before) {
                break;
            }

            $page++;
        }

        return array_values($ids);
    }

    private function fetchEntityData(string $entityId, string $conference): ?array
    {
        $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; church-loader/1.0)'])
            ->timeout(30)
            ->get(self::BASE_URL . '/ViewEntity.aspx', ['EntityID' => $entityId]);

        if (! $response->successful()) {
            $this->warn("  Skipped entity {$entityId} (HTTP {$response->status()})");
            return null;
        }

        return $this->parseEntityPage($response->body(), $entityId, $conference);
    }

    private function parseEntityPage(string $html, string $entityId, string $conference): array
    {
        $crawler = new Crawler($html);

        // Name
        $name = $this->spanText($crawler, '#_ctl0_lblName') ?? "Entity {$entityId}";

        // OMEntityID and OrgMastID are plain text in the .smallertype span
        $orgMastId = null;
        try {
            $headerText = $crawler->filter('span.smallertype')->first()->text('');
            if (preg_match('/OrgMastID:\s*(\S+)/i', $headerText, $m)) {
                $orgMastId = $m[1];
            }
        } catch (\Exception) {}

        // Build a label→value map from every <TR> that has a <STRONG> label cell.
        // Labels look like "Phone: " — normalize by stripping tags, collapsing
        // whitespace (including &nbsp; →  ), removing trailing colon, lowercasing.
        $labelMap = [];
        $crawler->filterXPath('//tr')->each(function (Crawler $tr) use (&$labelMap) {
            $tds = $tr->filterXPath('.//td');
            if ($tds->count() < 2) {
                return;
            }
            $raw   = strip_tags($tds->first()->text('', false));
            $label = preg_replace('/[\s\x{00A0}]+/u', ' ', $raw); // collapse all whitespace incl. &nbsp;
            $label = strtolower(trim(rtrim(trim($label), ':')));
            if ($label === '' || str_contains($label, "\n")) {
                return;
            }
            $labelMap[$label] = $tds->eq(1);
        });

        // Address — in the Street Address row the TD contains raw "line1<br>City ST ZIP<br>Country"
        $address = $city = $state = $zip = $country = null;
        try {
            $addrTd = $crawler->filterXPath(
                '//span[@id="_ctl0_lblStreetAddrPrefix"]/ancestor::tr[1]//td[2]'
            );
            if ($addrTd->count()) {
                // Convert <br> to newlines then split
                $addrHtml  = $addrTd->first()->html();
                $addrHtml  = preg_replace('/<br\s*\/?>/i', "\n", $addrHtml);
                $lines     = array_values(array_filter(array_map('trim', explode("\n", strip_tags($addrHtml)))));
                $address   = $lines[0] ?? null;
                if (isset($lines[1]) && preg_match('/^(.*?)\s+([A-Z]{2})\s+([\d\-]+)$/', $lines[1], $m)) {
                    $city  = $m[1];
                    $state = $m[2];
                    $zip   = $m[3];
                }
                $country = $lines[2] ?? null;
            }
        } catch (\Exception) {}

        $phone    = $this->labelValue($labelMap, 'phone');
        $website  = $this->labelHref($labelMap, 'website');
        $language = $this->labelValue($labelMap, 'language');

        // Entity type
        $entityType = $this->labelValue($labelMap, 'type');
        // Strip the short code in parens, e.g. "Primary (Elementary) School (EP)" → keep as-is or trim
        if ($entityType && preg_match('/^(.*?)\s*\([A-Z]{1,4}\)$/', $entityType, $m)) {
            $entityType = trim($m[1]);
        }

        // Pastor / Principal — label varies by entity type; the span tells us what to call it
        $pastor = null;
        $leaderLabel = strtolower($this->spanText($crawler, '#_ctl0_lblHeadAdminTitle') ?? 'pastor');
        $pastor = $this->labelValue($labelMap, $leaderLabel);

        // Latitude and longitude are in the "lat/lon" row value cell
        $latitude  = null;
        $longitude = null;
        $latLonText = $this->labelValue($labelMap, 'lat/lon');
        if ($latLonText && preg_match('/Latitude:\s*([-\d.]+)/i', $latLonText, $m)) {
            $latitude = (float) $m[1];
        }
        if ($latLonText && preg_match('/Longitude:\s*([-\d.]+)/i', $latLonText, $m)) {
            $longitude = (float) $m[1];
        }

        // Social media — dedicated rows in the label map + domain scan as fallback
        $facebook  = $this->labelHref($labelMap, 'facebook');
        $instagram = $this->labelHref($labelMap, 'instagram');
        $twitter   = $this->labelHref($labelMap, 'twitter') ?? $this->labelHref($labelMap, 'x');
        $youtube   = $this->labelHref($labelMap, 'youtube');

        // Anything else of interest goes into extra
        $extra = [];
        foreach (['fax', 'email', 'enrollment', 'source'] as $key) {
            $val = $this->labelValue($labelMap, $key);
            if ($val !== null) {
                $extra[$key] = $val;
            }
        }

        return [
            'om_entity_id' => $entityId,
            'org_mast_id'  => $orgMastId,
            'entity_type'  => $entityType,
            'name'         => $name,
            'address'      => $address,
            'city'         => $city,
            'state'        => $state,
            'zip'          => $zip,
            'country'      => $country,
            'latitude'     => $latitude ?: null,
            'longitude'    => $longitude ?: null,
            'phone'        => $phone,
            'website'      => $website,
            'pastor'       => $pastor,
            'language'     => $language,
            'facebook'     => $facebook,
            'instagram'    => $instagram,
            'twitter'      => $twitter,
            'youtube'      => $youtube,
            'extra'        => $extra ?: null,
        ];
    }

    private function spanText(Crawler $crawler, string $selector): ?string
    {
        try {
            $node = $crawler->filter($selector);
            if ($node->count() > 0) {
                $text = trim($node->first()->text('', false));
                return $text !== '' ? $text : null;
            }
        } catch (\Exception) {}
        return null;
    }

    /** Get trimmed plain-text value from the label map. */
    private function labelValue(array $labelMap, string $label): ?string
    {
        if (! isset($labelMap[$label])) {
            return null;
        }
        $text = trim($labelMap[$label]->text('', false));
        return $text !== '' ? $text : null;
    }

    /** Get href from the first <a> in a label-map value cell. */
    private function labelHref(array $labelMap, string $label): ?string
    {
        if (! isset($labelMap[$label])) {
            return null;
        }
        try {
            $a = $labelMap[$label]->filterXPath('.//a[@href]');
            if ($a->count()) {
                return $a->first()->attr('href') ?: null;
            }
        } catch (\Exception) {}
        return null;
    }
}
