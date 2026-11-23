# In-app help content

The Help pages (`/help`) render the Markdown files in this folder. There is no
build step: add or edit a file and reload.

## Layout

```
resources/help/
  en/                    one folder per language (codes match config/help.php)
    getting-started.md   one file per topic; the file name is the URL (/help/getting-started)
    media/               screenshots and videos used only by the English text
  es/                    a translation: same file names as en/
  media/                 media shared by every language
```

A topic that is missing in a language is shown in the default language (English)
with a notice, so translations can arrive one file at a time. Give a translated
topic the **same file name** as the English one.

## Front matter

Each topic starts with a block of `key: value` lines (flat values only):

```
---
title: Submitting a slide
summary: One line shown on the help index card.
group: For everyone       # heading on the index; topics with the same text are grouped
order: 20                 # lower comes first (default 100)
audience: users           # everyone (default) | users | managers | admin
---
```

`audience` hides the topic from people who shouldn't see it (it also 404s):
`users` = signed in, `managers` = site admins and church leaders/editors,
`admin` = site admins only.

## Writing

Standard GitHub-flavoured Markdown. Raw HTML is removed.

- Link to another topic with `[Shows](help:shows)` or `[text](help:shows#heading-slug)`.
  The link keeps the reader's language.
- Link to anywhere else with a normal URL; external links open in a new tab.

### Pictures and video

Put files under a `media/` folder and refer to them with `media:`:

```
![OBS output settings with the encoder set to x264](media:obs-encoder.png)

![Imaging the SD card, start to finish](media:pi-imaging.mp4)
```

- The **alt text is required**: it is what screen readers read, and what shows
  if the file is missing. For a video it becomes the player's label.
- A file is looked up in the reader's language folder first (`es/media/`), then
  the default language (`en/media/`), then the shared `media/` folder. So a
  Spanish screenshot of OBS can replace the English one just by using the same
  file name, and a screenshot with no text in it can live once in `media/`.
- `.png .jpg .jpeg .webp .gif` show as images; `.mp4 .webm` show as a video
  player. SVG is not served (see the SVG notes in ARCHITECTURE.md).
- **Captions:** a `.vtt` file with the same name as the video (`pi-imaging.vtt`)
  in the same media folder is attached automatically. Needed for a video with
  narration or on-screen text, and it is the per-language part of a video.
- **Keep the repo small.** Screenshots are fine in git (aim for under ~300 KB;
  crop to the relevant window). Anything bigger than a few MB should be hosted
  elsewhere and referenced by its full `https://` URL: `![alt](https://example.org/clip.mp4)`
  plays in the same player.
