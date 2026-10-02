<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Models\Entity;
use Illuminate\Http\Request;

/**
 * The location a widget sees as `api.location`: where the screen showing
 * the slide is. That's the screen's church (entity) when it has
 * coordinates, otherwise the site-wide default an admin sets on the
 * Widgets page (app_settings key 'widget_default_location'), otherwise
 * null. Lets one global slide (e.g. a weather widget with no ZIP code)
 * show each church its own local information.
 */
class WidgetLocation
{
    public const SETTING = 'widget_default_location';

    /** @return array{name: string, latitude: float, longitude: float, source: string}|null */
    public static function for(?Entity $entity): ?array
    {
        if ($entity && $entity->latitude !== null && $entity->longitude !== null) {
            $name = implode(', ', array_filter([trim((string) $entity->city), trim((string) $entity->state)]));

            return [
                'name'      => $name !== '' ? $name : $entity->name,
                'latitude'  => (float) $entity->latitude,
                'longitude' => (float) $entity->longitude,
                'source'    => 'entity',
            ];
        }

        $default = self::default();

        return $default ? $default + ['source' => 'default'] : null;
    }

    /**
     * For a web page: the entity the page is about — `?entity_id=` (the
     * board, Shows, Local Slides) or an {entity} route parameter — else the
     * default. The URL is the only source: nothing is remembered between
     * requests, so a bare URL is always the global view.
     */
    public static function forRequest(Request $request): ?array
    {
        $routeEntity = $request->route('entity');
        $id = $request->query('entity_id')
            ?? ($routeEntity instanceof Entity ? $routeEntity->id : $routeEntity);

        return self::for($id ? Entity::find((int) $id) : null);
    }

    /** @return array{name: string, latitude: float, longitude: float}|null */
    public static function default(): ?array
    {
        $value = AppSetting::get(self::SETTING);

        return is_array($value) && isset($value['name'], $value['latitude'], $value['longitude']) ? $value : null;
    }

    public static function saveDefault(?array $location): void
    {
        AppSetting::put(self::SETTING, $location);
    }
}
