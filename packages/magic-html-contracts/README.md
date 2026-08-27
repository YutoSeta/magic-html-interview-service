# Magic HTML Contracts

Executable API and data contracts shared by the independently deployed Magic HTML services.

This repository is the only allowed compile-time dependency shared by every service. It contains no provider implementation, database model, or orchestration logic.

## Dependency tiers

- Tier 0: atomic capabilities (`image`, `video`, `slide`, `document`, `static-compiler`)
- Tier 1: independent domain services (`content`, `collection`, `media`, `forms`, `styler`, `illustration`, `interview`, `knowledge`, `site-import`, `site-edit`, `preview`, `approval`)
- Tier 2: immutable publication assembly and multi-page site composition
- Tier 3: deterministic StaticSite build and approval-gated deployment operations (`build` then `release`)
- Product layer: Composer and its admin UI

Dependencies point from a higher tier to a lower tier. A lower tier must never import or call a higher tier, and cycles are forbidden. Tier 3 has ordered `build` and `release` substages so Deploy can re-resolve a Static Builder artifact without permitting the reverse dependency.

## Versioning

Every payload carries `contract_version`. Additive changes may remain in `v1`; removing or changing the meaning of a field requires a new major contract directory and endpoint prefix.

Run the repository checks with:

```bash
composer test
```

The OpenAPI descriptions are split into `openapi/tier0.json` through `openapi/tier3.json`. Individual JSON Schemas are in `schemas/v1` and are suitable for generated clients and contract fixtures. Provider calls and orchestration that can exceed an HTTP request deadline use the common asynchronous job model.

A Static Builder manifest digest has one canonical byte representation. Sort `files` by `path` in bytewise ascending order, concatenate `path + NUL + lowercase sha256 + NUL` for every file, then encode the SHA-256 of those concatenated bytes as lowercase hexadecimal. File size, content, and JSON serialization are not part of this digest input.

Content, Collection, and Media have separate databases and deployment environments. Their published snapshot versions are assembled by the Tier 2 Publication service into one self-contained immutable document. Referenced media bytes are copied into Publication-owned storage and integrity checked, so a later StaticSite build never depends on a live Content, Collection, Media, or legacy aggregate CMS API.

Site Composer accepts independent `image_limit` and `video_limit` budgets. Generated binary media is imported into the composed site's immutable asset set rather than retaining an AI provider's temporary output URL.

Illustration is a Tier 1 semantic material catalog built over the Tier 0 Image Adapter. Its finite Illustration Specification is shared by generation and search; the service compiles that specification into a provider-neutral prompt, imports successful image bytes into its own storage, and never exposes an upstream provider URL.

Site Edit is a Tier 1 deterministic transformation service. Target inspection accepts caller-supplied HTML and returns at most 200 bounded text, image, and contact-link candidates with canonical XPath targets. Candidate IDs are request-local, and `base_digest` binds the exact inspected HTML bytes to a later edit request. Inspection is synchronous and performs no persistence, external communication, or mutation; `truncated` reports either omitted candidates or shortened display fields.

Interview is a Tier 1 private authoring capability that runs a bounded scripted intake or imports an already collected website brief. Its versioned API starts, reads, advances, imports, and deletes interview sessions; completed structured data contains organization, goals, audience, tone, and optional requirements and materials. It does not generate a site, copy, media, or public content. Start and answer require a bounded Idempotency-Key and replay the original response for an exact retry. Answer also requires `expected_step`, so stale or concurrent UI events return 409 without being applied to a later question. Import keeps its optional legacy Idempotency-Key behavior.

Knowledge is a Tier 1 private retrieval capability isolated by tenant and knowledge base. Callers or dedicated source adapters resolve documents into bounded file bytes and metadata before ingestion; Knowledge never fetches an arbitrary remote URL, stores source credentials, or owns LINE WORKS, Slack, or another messaging surface. Idempotent multipart ingestion extracts, chunks, and indexes documents asynchronously. Search uses the versioned `weighted_rrf_v1` hybrid ranking contract with a stable chunk-ID tie-break. Answering defaults to the only v1 grounding policy, `strict_grounded`, and returns cited evidence or an explicit refusal rather than an unsupported answer.

Approval is a Tier 1 decision boundary for immutable artifact versions. It owns tenant- and site-scoped review requests, attributed conversation, the first final decision, required write idempotency, and keyed audit verification. It never fetches the artifact or its descriptive preview URL. Its Bearer principal is a trusted service; the caller remains responsible for end-user authentication, tenant authorization, and truthful actor assertions.

Document is a Tier 0 isolated rendering capability. Safe HTML preview is synchronous and side-effect free; PDF rendering is an idempotent asynchronous job with an authenticated status and artifact endpoint. The source HTML owns page size, orientation, and margins through CSS `@page` unless the request explicitly supplies `format`, `landscape`, or `margins`; those fields remain available as renderer overrides for existing integrations. `print_background` and `page_ranges` do not change page ownership. Job responses may report `page_source` as `css` or `renderer`; its absence identifies a legacy response and is interpreted as `renderer`, while the required legacy `format` field is only a fallback when `page_source` is `css`. HTML is treated as untrusted: executable or embedded content and external/local resources are rejected, JavaScript and redirects are disabled, outbound browser requests and host resolution are blocked, and a restrictive sandboxed CSP is injected. Generated PDF bytes remain on Document-owned private storage and expire with the job.

All OpenAPI paths are relative to the independently deployed service's `/api` base path. For example, `/v1/builds` describes the externally reachable `/api/v1/builds` route. This convention is shared by Tier 0 through Tier 3.

HTML Email Builder is a synchronous, deterministic, side-effect-free transformation. It requires service Bearer authentication and a closed request body, but no Idempotency-Key; identical payloads produce the same digest. Delivery remains a separate capability.

Deploy is the Tier 3 release boundary for immutable Static Builder artifacts. It owns tenant- and site-scoped provider environments, encrypts credentials at rest, and never returns credential values. GitHub and Cloudflare Pages support `preview`, `staging`, and `production`; SFTP and FTPS are limited to `preview` and `staging` in v1 because those drivers do not provide atomic cutover and rollback. Every environment targets a dedicated managed subtree: GitHub repository root and the SFTP/FTPS `/` root are forbidden deployment targets, and remote root paths are capped at 1700 characters so derived destinations remain within the 2048-character persistence boundary. Every write is idempotent. A deployment request accepts only a build UUID, SHA-256 manifest digest, and Approval request UUID; the service resolves both records from fixed service-configured APIs and rejects caller-supplied URLs. It deploys only a succeeded build whose digest matches an approved, matching artifact with a server-verified valid Approval history.
