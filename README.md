# Letterboxd Connect

A WordPress plugin that brings your [Letterboxd](https://letterboxd.com) diary onto your own site. Every film you log becomes a post in a **Movies** section, with your rating, your review, the day you watched it and the poster. Director, IMDb link and genres come from [TMDB](https://www.themoviedb.org). A **Movie Grid** block shows them anywhere on your site.

**[Download the latest version](https://github.com/dcstagg/letterboxd-connect/releases/latest/download/letterboxd-connect.zip)** · [Website](https://dcstagg.github.io/letterboxd-connect/) · [Changelog](CHANGELOG.md)

## What it does

- Checks your Letterboxd diary on a schedule (hourly to weekly) and adds new entries as posts, dated the day you watched them. Rewatches are separate posts.
- Keeps up with you: change a rating, write or edit a review, or fix a date on Letterboxd, and the post follows, unless you've edited that part in WordPress.
- Never overwrites your own edits, and never brings back a movie you deleted.
- Fills in director, IMDb ID, genres and a missing poster from TMDB, and keeps retrying in the background when a service is down.
- Imports your whole history from the Letterboxd export ZIP, beyond the 50 entries the feed holds.
- Movie Grid block: posters as cards or a list, with ratings, director, where to watch, and IMDb and Rotten Tomatoes links; paged, accessible, and styled to fit your theme.
- Tells you on the dashboard when something needs attention: an import failing, imports that stopped running, or entries that may have been missed.

## Requirements

- WordPress 6.6 or newer
- PHP 8.4 or newer
- A public Letterboxd account
- Optional, recommended: a free [TMDB API key](https://www.themoviedb.org/settings/api) for directors, IMDb links and posters

## Install

1. Download [letterboxd-connect.zip](https://github.com/dcstagg/letterboxd-connect/releases/latest/download/letterboxd-connect.zip).
2. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**, choose the ZIP and activate it.
3. Open **Settings → Letterboxd Connect**, enter your Letterboxd username and, if you have one, your TMDB API key, and save.
4. Press **Import Now**. Your recent diary appears under **Movies**.
5. Add the **Movie Grid** block to any page.
6. Optional: import your Letterboxd export (letterboxd.com → Settings → Import & Export → Export your data) to bring in everything older.

To update, download the new ZIP and upload it the same way; WordPress offers to replace the installed version. Your movies and settings stay.

## How importing works

- The diary feed (`letterboxd.com/<username>/rss/`) is checked on a schedule (hourly to weekly, or never) and with **Import Now**. It holds your last 50 diary entries.
- Each diary entry becomes one movie post, dated the day you watched it, with your rating, the film's year, the review (or "Watched on …"), the poster as featured image, and a link to the entry on Letterboxd. Rewatches are separate posts.
- Movies already on the site keep your edits. Empty fields are filled in, and a change you make on Letterboxd afterwards (a new or edited review, a different rating, a corrected date) is copied over while the entry is still in the feed, unless you've edited that field here. What Letterboxd last said is kept in `_lbc_source`, which is how the two are told apart.
- Editing a movie by hand: changing its day watched moves its post date to match (so lists sorted by date agree), changing its year updates the Year archive, and a poster you remove stays removed. A movie you trash or delete isn't imported again (deleted ones are remembered in the `lbc_deleted` option; **Forget Them** on the settings page clears that).
- Posters and TMDB details that fail to load are retried by a background job. If TMDB is down it waits 15 minutes; a poster that won't download is tried again an hour after the rest, three times at most. Adding a TMDB key starts it for every movie missing details. **Fill In Missing Details** retries every movie that's still missing something.
- **Import a Letterboxd export**: the ZIP from letterboxd.com → Settings → Import & Export. It corrects watch dates and fills in missing ratings on movies already here, and can add the diary entries the feed no longer has.

## When something goes wrong

Problems show on the dashboard, Plugins and Movies screens as well as the settings page: a failed import, a feed whose format looks changed, every feed entry being new (entries may have dropped out of the feed unseen, so import your export), or no import for three times the schedule.

WP-Cron only runs when someone visits a page that isn't cached. On a quiet, cached site that can stall imports for weeks, and the feed only holds the last 50 entries. A server cron job that requests `wp-cron.php` every 15 minutes (with `define( 'DISABLE_WP_CRON', true );` in wp-config.php) makes them reliable.

## Fields

| Meta key | Holds |
| --- | --- |
| `watch_date` | Day watched, `YYYY-MM-DD` |
| `movie_rating` | Your rating out of 5: `3.5` |
| `movie_year` | Release year (also the **Year** taxonomy, `/movie-year/2024/`) |
| `director` | From TMDB |
| `letterboxd_url` | The diary entry on Letterboxd |
| `letterboxd_guid` | The diary entry's ID, used to recognise it on later imports |
| `tmdb_id`, `imdb_id`, `streaming_link`, `poster_url`, `tmdb_*` | From the feed and TMDB |

## Upgrading from 1.x

Install 2.0 over 1.x as above; the upgrade happens by itself on the first page load after the files are updated: settings move to one option (`lbc_settings`), 1.x's options, transients, cron hooks and role capabilities are removed, ratings stored as stars become numbers, titles saved as `&amp;` are fixed, and movies imported without an author are credited to the first administrator.

Uninstalling removes the plugin's settings only. Movies stay.

## Credits

Letterboxd Connect isn't affiliated with or endorsed by Letterboxd. It reads your public diary feed, the same one any feed reader can.

This product uses the TMDB API but is not endorsed or certified by TMDB.

## License

GPL v3 or later. See [LICENSE](LICENSE).
