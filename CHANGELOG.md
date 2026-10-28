# Changelog

## 2.0.6 — 2026-10-28

- The first public release of 2.0, with a [website](https://dcstagg.github.io/letterboxd-connect/) and this changelog.
- The settings page shows the plugin's version, links to the website, and credits TMDB.
- The background job's status no longer nests its "overdue" warning inside parentheses.
- WordPress won't offer an unrelated plugin of the same name from wordpress.org as an update.

## 2.0.5 — 2026-10-28

- Movie titles saved with `&amp;` instead of `&` are repaired again on upgrade, including ones encoded twice by 1.x, which the first repair missed.

## 2.0.4 — 2026-10-28

- A date corrected on Letterboxd no longer turns `&` in the movie's title into `&amp;` when the change arrives in a scheduled import, and no longer publishes a movie you'd scheduled.
- A rating you add on Letterboxd after a film was imported is followed from then on.
- An image or heading you add to a review by hand counts as an edit, so a later change on Letterboxd doesn't replace it.
- A feed with only lists in it isn't reported as a changed feed format.
- Importing your export clears the "entries may have been missed" warning.
- Posters you removed by hand no longer count as missing.
- Export reviews keep links written with single quotes or extra attributes, and underline and strikethrough.
- A TMDB rate limit pauses the background job for 15 minutes instead of being reported as an API change.

## 2.0.3 — 2026-10-28

- Changes you make on Letterboxd (a new or edited review, a different rating, a corrected date) now reach the post while the entry is in your feed, field by field, unless you've edited that field in WordPress.
- Renaming a movie by hand, or TMDB filling in its year, no longer makes an export import add it a second time.
- Same-day rewatches stay separate posts, each with its own rating and review.
- Changing a movie's watch date moves its post date to match; changing its year updates the Year archive; a poster you remove stays removed.
- A rewatch reuses the first watch's poster instead of downloading it again.
- Ratings typed as "4/5" or "7/10" are understood.
- Failing imports, a feed format that looks changed, imports that stopped running, and entries that may have been missed while you were away now show as a notice on the dashboard.
- The background job notices when TMDB itself has stopped finding films, and pauses instead of giving up on every movie.
- Review text is cleaned more safely: nothing in a review can add other site content to the page, and text like "x<y" or "</3" is kept as written.
- Large export files can't run the server out of memory.

## 2.0.2 — 2026-10-28

- A poster that can never download no longer stops the background job; it's retried a few times while the other movies carry on.
- Saving a new TMDB key fills in details for every movie straight away.
- A username Letterboxd doesn't have, or a key TMDB rejects, isn't saved.
- The settings page shows what the background job is doing, warns when scheduled tasks are running late, and can forget the movies you deleted so they can be imported again.
- Movie Grid: page links jump back to the grid, links say which film they're for and that they open a new tab, posters load lazily at the right size, and the RT button meets contrast guidelines.
- The Movies list can be sorted by date watched and rating.

## 2.0.1 — 2026-10-28

- Rewatches imported from an export are no longer mistaken for one another.
- The background job keeps going after a crash, and asks TMDB for a poster when that's all a movie is missing.
- Movie Grid: "/page/1/" goes to the page itself, and pages past the last are "not found".

## 2.0.0 — 2026-10-28

A rewrite from scratch, a quarter of the size of 1.x. Your movies, their URLs and your Movie Grid blocks carry over, and the upgrade from 1.x runs by itself.

- Ratings are imported (1.x lost them), and your edits to movies are never overwritten.
- Watch dates are the day you watched, not the day you logged it, which in 1.x was often a day late.
- Movies you delete aren't imported again, and nothing is imported twice.
- A background job fills in missing posters, directors and IMDb links.
- Import your Letterboxd export to bring in everything older than the feed's last 50 entries.
- A simpler settings page that shows what happened on the last import and what's scheduled next.
- Uninstalling keeps your movies.

## 1.0 — 2025

The first release.
