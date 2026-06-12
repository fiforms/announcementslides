# AnnouncementSlides

A web-based announcement slide distribution system designed for Seventh-day Adventist churches. Administrators and authorized contributors can push slides to any church in the system; registered users can submit slides for review; and church leader users can configure slides specific to their local congregation.

> For a tour of the project layout, data model, and where the implementation lives, see [ARCHITECTURE.md](ARCHITECTURE.md).

## Features

- **Multi-tier role system** — admins, contributors, and church leaders each have scoped permissions
- **Church directory integration** — loads congregations from adventistdirectory.org, organized by conference
- **Slide submission and review workflow** — users submit slides that go through an approval process before distribution
- **Google OAuth login** — optional single sign-on via Google accounts
- **Multilingual support** — i18n-ready with per-language configuration

## Requirements

- PHP 8.4+
- Composer
- Node.js / npm
- SQLite (default) or a supported relational database

## Installation

```bash
git clone <repo-url> announcementslides
cd announcementslides

composer install
npm install
```

Copy the environment file and generate an application key:

```bash
cp .env.example .env
php artisan key:generate
```

Run database migrations:

```bash
php artisan migrate
```

Build frontend assets:

```bash
npm run build
```

### Create the first user

```bash
php artisan user:create
php artisan user:setrole       # promote the user to admin
```

`user:setrole` will prompt for an email address and a role (`admin`, `contributor`, or `viewer`).

### Load church data

```bash
php artisan church:load        # load entities for a conference from adventistdirectory.org
php artisan entity:sync        # sync the local entities table
```

## Google OAuth Setup

To enable Google single sign-on, create OAuth 2.0 credentials in the [Google Cloud Console](https://console.cloud.google.com/):

1. Create a project and enable the **Google+ API** (or **Google Identity**).
2. Under **Credentials**, create an **OAuth 2.0 Client ID** of type *Web application*.
3. Add your app URL as an authorized origin and set the redirect URI to:
   ```
   https://your-domain.com/auth/google/callback
   ```
4. Copy the client ID and secret into `.env`:
   ```ini
   GOOGLE_CLIENT_ID=your-client-id
   GOOGLE_CLIENT_SECRET=your-client-secret
   GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"
   ```

Users can then sign in with their Google account from the login page.

## Artisan Commands

| Command | Description |
|---|---|
| `user:create` | Create a new user account |
| `user:list` | List all users (optionally filter by role) |
| `user:setrole` | Set a user's role |
| `user:setpassword` | Set a user's password |
| `church:load` | Load Adventist entities for a conference from AdventistDirectory.org |
| `church:list` | List all entities in the database |
| `church:detail` | Show all stored fields for a single entity |
| `entity:sync` | Sync the entities table |
| `entity:assign` | Assign or remove a user role for an entity |
| `language:add` | Add a supported language |
| `language:list` | List all supported languages |

## Development

Start the development server:

```bash
php artisan serve
npm run dev
```

## License

MIT License (see LICENSE.md)
