// Show folders are a naming convention, not data: a show named
// "Channels/3ABN" is listed as "3ABN" inside a "Channels" folder. Only the
// first "/" counts (one level, since <optgroup> can't nest and deep menus
// are awkward on a remote), spaces around it are ignored, and a name with an
// empty side ("/X", "X/") stays a plain name. Management screens keep showing
// the full name, so it's clear what renaming would change.
//
// Kept in step with the web app's copy in
// resources/js/Composables/showFolders.js.

export const FOLDER_SEPARATOR = '/'

/** {folder, leaf} for a foldered name, or null for a plain one. */
export function splitShowName(name) {
  if (typeof name !== 'string') return null
  const at = name.indexOf(FOLDER_SEPARATOR)
  if (at < 0) return null
  const folder = name.slice(0, at).trim()
  const leaf = name.slice(at + 1).trim()
  return folder && leaf ? { folder, leaf } : null
}

/**
 * Groups shows for a list, keeping their order: each folder sits where its
 * first show was. Returns entries that are either
 *   { show }                                  — a show at the top level, or
 *   { folder, items: [{ show, label }, …] }   — a folder of shows (label = leaf name).
 * `foldable(show)` keeps some shows (e.g. the main show) out of folders.
 */
export function groupShows(shows, { foldable = () => true } = {}) {
  const entries = []
  const folders = new Map()
  for (const show of shows ?? []) {
    const parts = foldable(show) ? splitShowName(show.name) : null
    if (!parts) {
      entries.push({ show })
      continue
    }
    let entry = folders.get(parts.folder)
    if (!entry) {
      entry = { folder: parts.folder, items: [] }
      folders.set(parts.folder, entry)
      entries.push(entry)
    }
    entry.items.push({ show, label: parts.leaf })
  }
  return entries
}
