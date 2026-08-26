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

## Verification

```bash
composer install
php artisan test --compact
composer validate --strict
composer audit --no-dev
```
