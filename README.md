# Magic HTML Interview Service

A conversational intake service that turns a bounded sequence of answers into a structured website brief. The session is a private authoring artifact and can be consumed by any orchestrator.

It does not generate Site AST, wireframes, copy, images, styles, or public content.

## API

All `/api/v1` operations require `Authorization: Bearer <MAGIC_HTML_SERVICE_TOKEN>`.

- `POST /api/v1/interviews` — start a chat session
- `POST /api/v1/interviews/{interview}/messages` — answer the current question
- `GET /api/v1/interviews/{interview}` — inspect messages and structured result
- `POST /api/v1/interviews/import` — register an already collected brief
- `DELETE /api/v1/interviews/{interview}`

The completed `structured_data` has `organization`, `goals`, `audience`, `tone`, `requirements`, and `materials` fields. Import exists so API orchestrators and the interactive chat share one normalized contract.

### Generic intake

The fixed website brief above is one script. Any other hearing is a Generic Intake template: an orchestrator (a person, an agent, another service) registers the `path`-keyed fields it needs, opens sessions against it, hands the temporary signed browser URL to whoever answers, and reads the confirmed values back. The question set is therefore decided by the caller, not by this service.

- `PUT /api/v1/intake-templates/{template}` — register an immutable intake definition (`title`, `fields[]` per `template/field.json`, `mode` chat | form, `greeting`, `closing_script`); an identical re-registration replays 200, a different one 409
- `POST /api/v1/intake-templates/{template}/sessions` — open a session and get a signed browser URL (`/i/{interview}`)
- `GET /api/v1/intake-sessions/{interview}` — state and confirmed values

The landing-page hearing Neo Styler reads (`site_name`, `offer`, `cv_goal`, `tel`, `links`, `business_name` …) ships in the contracts as `tests/fixtures/neo-styler/landing-intake-template.json`; register it as `landing-page` and it becomes the brief for `propose` and `materialize`.

## Verification

```bash
composer install
php artisan test --compact
composer validate --strict
composer audit --no-dev
```
