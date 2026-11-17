# Culinary Preferences Organizer

A web application for managing the culinary preferences of a person or a group of people, built on
its own REST API and on the open data of [Open Food Facts](https://world.openfoodfacts.org).
It covers food (meta)data, dietary restrictions, group consensus, shopping lists, user administration
and statistics that can be exported in open formats (CSV and PDF).

## Features

- **Products and their metadata**: category, price, ingredients, images, allergens (contains / traces),
  vegetarian and vegan flags, Nutri-Score, NOVA group, Eco-Score, perishability, shelf life, expiry date,
  availability by season and region, restaurants that serve the product.
- **Open Food Facts integration**: search the OFF catalogue, preview a product by barcode, import it
  (allergens, category, diet flags and countries are mapped to the local data) and re-sync it later.
- **Preferences**: love / like / neutral / dislike / avoid, with an optional rating and notes, for products,
  categories and restaurants; personal recommendations based on liked categories, restrictions and season.
- **Restrictions**: a diet (omnivore, vegetarian, vegan, pescatarian) and allergens to avoid
  (*avoid* or *strict*) per user; products are checked against them everywhere.
- **Groups**: members, the restrictions they have in common and a consensus of favourites,
  conflicts and vetoed items.
- **Shopping lists**: personal or shared with a group, budget, checking off items, duplicating a list,
  generating one automatically from preferences that respects every member's restrictions.
- **Statistics**: ten reports (overview, preferences by sentiment, top products, categories, allergens,
  Nutri-Score, perishability, seasonality, spending, most bought items) for the user, a group or the whole
  platform (admins), shown as charts and tables and exported as **CSV** or **PDF** (also as one full PDF report).
- **Currencies**: prices are stored in euro; users in a country outside the euro area also see them in their
  local currency, converted at the latest European Central Bank reference rate.
- **Administration**: users (roles, deactivation, password reset, CSV/PDF export), categories and allergens.
- Light and dark theme, responsive layout, API documentation with Swagger UI.

## Technology

| Part      | Stack |
|-----------|-------|
| Backend   | PHP 8.5, Laravel 13, Laravel Sanctum (Bearer tokens), DomPDF, PostgreSQL 18 |
| Frontend  | React 19, TypeScript 6, Vite 8, React Router 7, TanStack Query 5, Recharts 3, pnpm |
| Dev setup | Docker via Laravel Sail (Laravel, scheduler, PostgreSQL and the Vite dev server) |
| Data      | Open Food Facts API, ECB exchange rates via [Frankfurter](https://frankfurter.dev) |

## Project structure

```
backend/                 Laravel REST API
  app/Http/Controllers/Api/   one controller per resource (+ Admin/)
  app/Services/               Open Food Facts client, statistics, CSV/PDF export, exchange rates
  app/Models/                 Eloquent models
  database/migrations/        schema;  database/seeders/ reference and demo data
  routes/api.php              all API routes;  routes/console.php  scheduled tasks
  resources/views/pdf/        PDF templates
  public/docs/                OpenAPI 3.1 specification and Swagger UI
  tests/Feature/ApiTest.php   end-to-end API tests (PostgreSQL)
  compose.yaml                Docker services
frontend/                React single-page application
  src/api/                    HTTP client, types, labels, shared queries
  src/auth/                   session context and route guards
  src/components/             layout and reusable UI components
  src/pages/                  one component per page
```

## Getting started

Requirements: Docker Desktop and Git. PHP, Composer and Node are not needed on the host.

```bash
git clone https://github.com/lucadani7/CulinaryPreferencesOrganizer.git
cd CulinaryPreferencesOrganizer/backend
cp .env.example .env
```

Install the PHP dependencies. With PHP and Composer on the host, run `composer install`; otherwise use the
one-off Docker command from the Laravel documentation,
[Installing Composer Dependencies for Existing Applications](https://laravel.com/docs/sail#installing-composer-dependencies-for-existing-projects).
Then:

```bash
./vendor/bin/sail up -d                      # Laravel, scheduler, PostgreSQL, frontend
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed     # schema + reference data + demo data
./vendor/bin/sail artisan storage:link       # serves uploaded product images
./vendor/bin/sail artisan fx:refresh         # downloads today's exchange rates
```

The first start of the `frontend` container installs the Node packages, which takes a minute or two
(`sail logs -f frontend`).

| URL | What |
|-----|------|
| http://localhost:5173 | the application |
| http://localhost:8000/docs | API documentation (Swagger UI) |
| http://localhost:8000/api | REST API |

Tip: `alias sail='./vendor/bin/sail'`.

### Demo accounts

All demo accounts use the password `password`.

| Email | Role | Profile |
|-------|------|---------|
| `admin@cpo.test` | admin | |
| `ana@cpo.test` | user | vegetarian, strict peanut allergy, owner of the "Popescu Family" group |
| `ion@cpo.test` | user | avoids milk, member of the "Popescu Family" group |

Demo data is not created in production unless `SEED_DEMO=true`.

## API

All endpoints are under `/api` and return JSON. Apart from `/health`, `/auth/register` and `/auth/login`,
they require a token: `Authorization: Bearer <token>` (the token is returned by login and register).
The full reference, with request bodies and responses, is in Swagger UI at `/docs`.

| Area | Endpoints |
|------|-----------|
| Auth | `POST /auth/register`, `POST /auth/login`, `POST /auth/logout`, `GET/PATCH /auth/me`, `PUT /auth/me/password` |
| Catalogue | `/products` (CRUD, filters), `/products/{id}/images`, `/products/{id}/sync`, `GET /categories`, `GET /allergens` |
| Open Food Facts | `GET /off/search`, `GET /off/products/{barcode}`, `POST /off/import` |
| Restaurants | `/restaurants` (CRUD), `/restaurants/{id}/products` |
| Preferences | `/preferences` (CRUD), `GET /preferences/recommendations` |
| Groups | `/groups` (CRUD), `/groups/{id}/members`, `GET /groups/{id}/consensus` |
| Shopping lists | `/shopping-lists` (CRUD), `/generate`, `/{id}/duplicate`, `/{id}/items`, `/{id}/export` |
| Statistics | `GET /stats`, `GET /stats/{report}`, `GET /stats/{report}/export`, `GET /stats/export/all` |
| Exchange rates | `GET /exchange-rates` |
| Administration | `/admin/users` (CRUD, export), `/admin/categories`, `/admin/allergens`, `POST /admin/exchange-rates/refresh` |

Exports take `?format=csv` or `?format=pdf`; statistics take `?scope=me|group|global` (and `group_id`).
CSV files are UTF-8 with a BOM (they open correctly in Excel) and are protected against formula injection.

## Tests

```bash
cd backend
sail artisan test
```

The tests run against the `testing` PostgreSQL database that Sail creates; external services
(Open Food Facts, exchange rates) are faked. The frontend is type-checked with `pnpm typecheck` in `frontend/`.

## Configuration

The application-specific settings are documented in `backend/.env.example` and `frontend/.env.example`:

- `OFF_USER_AGENT`: Open Food Facts asks every client for a descriptive User-Agent with a contact email.
- `CORS_ALLOWED_ORIGINS`: frontend origins allowed to call the API when they are on another domain.
- `FX_RATES_URL`, `FX_MAX_AGE_HOURS`, `FX_AUTO_REFRESH`: exchange rates. The `scheduler` service runs
  `fx:refresh` on working days at 16:30 Berlin time, after the ECB publishes its rates.
- `TRUSTED_PROXIES`: set when the API runs behind a reverse proxy.
- `VITE_API_URL` (frontend): base URL of the API in production; empty means the same origin.

## Data sources and licences

- Product data comes from [Open Food Facts](https://world.openfoodfacts.org), available under the
  [Open Database License (ODbL)](https://opendatacommons.org/licenses/odbl/1-0/); product images are
  licensed by their contributors under CC BY-SA. The application credits Open Food Facts in its interface,
  caches the responses and respects the API rate limits.
- Exchange rates are the euro foreign exchange reference rates of the European Central Bank, retrieved
  through the Frankfurter API.

The source code is released under the [Apache License 2.0](LICENSE).
