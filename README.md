# Letterboxd Connect
- Contributors: dcstagg
- Tags: letterboxd, movies, films, import, tmdb
- Requires at least: 5.0
- Tested up to: 6.7.2
- Stable tag: 1.0
- Requires PHP: 7.4
- License: GPLv3 or later
- License URI: http://www.gnu.org/licenses/gpl-3.0.html

Import your Letterboxd film diary into WordPress as custom movie posts with metadata, posters, and director information.

## Description

The Letterboxd Connect Plugin bridges the gap between your Letterboxd film log and your WordPress site. Letterbox does not offer an API, so this plugin uses the RSS feed of your username to automatically imports your watched films. L2WP creates custom movie posts with optional metadata including ratings, watch dates, posters, and more.

## Key Features

- **Automatic Film Import**: Import your Letterboxd diary entries via RSS feed
- **Custom Movie Post Type**: Films are stored as a dedicated movie post type with custom fields
- **TMDB Integration**: Enrich film data with posters, directors, and streaming information from [The Movie Database](https://www.themoviedb.org/)
- **Scheduled Imports**: Configure automatic imports to keep your site in sync with Letterboxd
- **Gutenberg Block**: Display your films in grid or list view with customizable settings
- **Year Taxonomy**: Films are automatically categorized by release year

## Installation

1. Download the plugin zip file
2. Navigate to your WordPress admin area and go to Plugins > Add New
3. Click "Upload Plugin" and select the downloaded zip file
4. Activate the plugin through the 'Plugins' menu

## Configuration

### Basic Setup

1. Go to Settings > Letterboxd Connect
2. Enter your Letterboxd username
3. Configure import settings (draft status, start date for import)
4. Save your settings

### TMDB Integration (Optional but Recommended)

For enhanced film data including posters, directors, and streaming information:

1. Create a free account at [The Movie Database (TMDB)](https://www.themoviedb.org/signup)
2. Grab your API key on the [TMDB Settings > API screen](https://www.themoviedb.org/settings/api)
3. Enter your TMDB API key in the plugin settings
4. Test the connection to verify it's working

## Usage

### Manual Import

1. Visit Settings > Letterboxd Connect page
2. Check the box that says "Run Import After Save"
2. Save the settings
3. The plugin will fetch your latest Letterboxd entries and create corresponding movie posts

### Automatic Import

1. Enable scheduled imports in the settings
2. Select your preferred frequency (hourly, daily, weekly)
3. Optionally enable email notifications for import results

### Displaying Movies

Use the custom Gutenberg block to showcase your movies:

1. Add a new block in the editor and search for "Movie Grid"
2. Configure display options (number of movies, columns, sort order)
3. Choose between card view (with posters) or list view
4. Publish your page or post

## Frequently Asked Questions

- **How many films can I import?**
The plugin can handle your entire Letterboxd diary. Imports are batched, so limitations are based on your server resources and WordPress configuration.

- **Do I need a TMDB API key?**
No, but it's highly recommended. Without it, you'll miss additional metadata like posters and director information.

- **How often should I schedule imports?**
This depends on how frequently you log films on Letterboxd. Daily imports work well for most users.

- **Can I import films from a specific date?**
Yes, you can set a start date in the plugin settings to limit which films are imported.

## Troubleshooting

**Import Issues**
- Verify your Letterboxd username is correct
- Check that your Letterboxd profile is public
- Ensure your server allows outgoing connections to Letterboxd's RSS feeds

**TMDB Connection Problems**
- Confirm your API key is entered correctly
- Check that your server allows connections to the TMDB API
- Verify your TMDB account is in good standing

## Changelog

- 1.0
* Initial release

## Upgrade Notice

- 1.0
Initial release of Letterboxd Connect.

## Screenshots

Coming soon!

## Credits

- Uses the [TMDB API](https://www.themoviedb.org/documentation/api) for enhanced film data
- Inspired by the Letterboxd community