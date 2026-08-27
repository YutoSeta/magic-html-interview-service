# Service dependency policy

## Direction

The service graph is a directed acyclic graph:

```text
Deploy release stage (Tier 3)
        |
        +--> Static Builder build stage (Tier 3)
        |           |
        |           +--> Publication / Site Composer (Tier 2)
        |                       |
        |                       +--> Content / Collection / Media / Styler / Illustration (Tier 1)
        |                                         |
        |                                         +--> Image / Video / Slide / Document / Static Compiler (Tier 0)
        |
        +--> Approval (Tier 1)
```

External AI providers and infrastructure are outside this graph. A Tier 0 adapter may call its declared provider, but it cannot call a Magic HTML service in Tier 1 or above.

Tier 3 has two ordered substages represented in one contract inventory: `build` and `release`. Deploy may call Static Builder to re-resolve an immutable build, but Static Builder must never call Deploy. This ordered edge is the only permitted same-number tier dependency and cannot form a cycle.

## Runtime rules

1. Services never share database tables, queues, or storage credentials.
2. Cross-service calls use the versioned HTTP contract only.
3. Long-running mutations accept an `Idempotency-Key` and expose a normalized job state.
4. Build services consume self-contained immutable snapshots, not another service's live database or mutable public asset URL.
5. Provider output URLs are temporary; a domain media service owns durable assets.
6. Every deployed service exposes `/up`, `/api`, and `/api/__verify`.
7. Publication records exact source versions and digests, copies referenced media into its own storage, and is the only content-data source accepted by Static Builder.
8. Site Composer may request bounded image and video generation. It copies successful provider output into its own immutable site assets before publishing; temporary provider URLs are never snapshot dependencies.
9. Illustration owns its searchable semantic catalog and durable image bytes. It may call the Tier 0 Image Adapter, but the adapter never knows about catalog items or Illustration Specifications.
10. Approval owns its decision and audit database. It receives an immutable artifact identity and digest, never fetches the artifact or preview URL, and has no runtime dependency on the artifact producer.
11. Approval's Bearer token identifies a trusted calling service. That caller authenticates the end user, authorizes tenant/site access, and supplies truthful actor identity; the Approval service still scopes every lookup by both tenant and site.
12. Approval POST operations require a globally unique `Idempotency-Key`. The first final decision wins, messages remain appendable after a decision, and consumers must reject histories whose server-verified `integrity.valid` is false.
13. Document is a Tier 0 capability with no Magic HTML runtime dependency. It accepts caller-supplied HTML but never accepts a source URL or fetches remote assets.
14. Document preview is a synchronous, side-effect-free HTML response and does not require an idempotency key. PDF rendering is asynchronous; creation requires an `Idempotency-Key`, exposes a pollable job, and stores its artifact on private Document-owned storage. Idempotent replay is guaranteed while the job and key pointer remain within their configured retention period.
15. Document rejects executable and embedded markup, unknown payload keys, external/local resource URLs, and CSS imports. JavaScript, redirects, browser requests, and host resolution are disabled or blocked, and preview HTML is returned with a sandboxed restrictive CSP.
16. Deploy owns tenant- and site-scoped provider environments for GitHub, Cloudflare Pages, SFTP, and FTPS. Stored credentials are encrypted, are accepted only when creating an environment, and are never returned by resource or list endpoints. GitHub and Cloudflare Pages support `preview`, `staging`, and `production`; SFTP and FTPS are limited to `preview` and `staging` in v1 because they have no atomic cutover and rollback boundary. Every environment targets a dedicated managed subtree; GitHub repository root and the SFTP/FTPS `/` root are forbidden deployment targets. SFTP and FTPS remote root paths are capped at 1700 characters so derived destinations remain within the 2048-character persistence boundary.
17. Deploy POST operations require a globally unique `Idempotency-Key`. Exact retries replay the original status and body; key reuse for another operation or input is rejected.
18. A deployment request accepts only `contract_version`, a Static Builder `build_id`, its SHA-256 `artifact_digest`, and an Approval `approval_request_id`. Artifact, manifest, approval, and service URLs are never caller-controlled.
19. Before accepting or executing a deployment, Deploy re-resolves the build and approval from fixed service configuration. It requires a succeeded build and matching manifest digest, a final approved tenant/site-scoped Approval record identifying that same `static_build` and digest, and an Approval history whose server-verified integrity is valid.
20. A Static Builder manifest digest is canonical across Builder, Approval, and Deploy. Sort `files` by `path` in bytewise ascending order, concatenate `path + NUL + lowercase sha256 + NUL` for each file, and encode the SHA-256 of those concatenated bytes as lowercase hexadecimal. File size, content, and JSON serialization are excluded from the digest input.
21. Site Edit target inspection is a synchronous, side-effect-free Tier 1 transform over caller-supplied HTML. It performs no external communication or persistence, returns at most 200 finite text, image, and contact-link targets, and binds the response to the exact input bytes with a lowercase SHA-256 `base_digest`.
22. Site Edit candidate IDs are request-local. Consumers must use the canonical XPath and `base_digest` from the same inspection when proposing a later edit, and must treat `truncated` as notice that candidates were omitted or display fields were shortened to their contract bounds.

## Tier calculation

A package with no internal service dependency has depth 0. Otherwise its depth is one plus the greatest depth among its internal dependencies. CI rejects a dependency whose rank is greater than or equal to the caller's rank, and rejects every cycle. Rank normally equals the numbered tier; within Tier 3 only, `release` ranks above `build`.
