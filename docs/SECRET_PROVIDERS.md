# Secret providers (linked secrets)

A **linked** secret keeps a *reference* to a value held by an external provider instead of the value itself. Falak
resolves it on the control plane when a deployment (or a build) needs it, so the value never has to be copied into
Falak by hand. Add providers under **Settings → Secret providers**; create a linked secret on any Secrets page
(**Source: Linked to a provider**).

## Providers and references

| Provider | Reference | Authentication |
|---|---|---|
| HashiCorp Vault / OpenBao | `vault://<mount>/data/<path>#<key>` (KV v2) · `vault://<mount>/<path>#<key>` (KV v1, set on the provider) | token, AppRole (role id + secret id), JWT / OIDC role; namespace; custom CA |
| AWS Secrets Manager | `aws-sm://<name or ARN>` · `aws-sm://<name>#<json key>` (one key of a JSON secret) | access key (+ session token), optional STS AssumeRole (role ARN, external id) |
| AWS SSM Parameter Store | `aws-ssm:///<path/name>` (or `aws-ssm://<name>`; read `WithDecryption`) | same as Secrets Manager |
| 1Password | `op://<vault>/<item>/<field>` · `op://<vault>/<item>/<section>/<field>` | a **1Password Connect** server URL and token |
| Doppler | `doppler://<project>/<config>/<NAME>` | service token |
| Infisical | `infisical://<project id>/<environment>[/<path>…]/<NAME>` | machine identity, universal auth (client id + secret); cloud or self-hosted URL |
| HTTPS webhook | `https://<base URL>/<name>` | optional header (`Authorization: Bearer …`, `X-Api-Key: …`) |

- A reference must match its provider's type; Falak checks it when the secret is saved. When an organization has
  exactly one provider of a reference's type, the provider can be left out and that one is used.
- Values are text. Vault and AWS JSON values pick one key (`#key`); numbers and booleans become their JSON text.
- **1Password service accounts** only work through 1Password's SDKs and CLI. Run a
  [Connect server](https://developer.1password.com/docs/connect/) (it can use the same vaults) and give Falak its URL
  and token.
- 1Password lookups must be unambiguous: two vaults, items or fields with the same name (and no section to tell the
  fields apart) are an error, as is an empty field.
- **AWS instance profile.** On an instance where the control plane runs on EC2, the operator can let AWS providers use
  the control plane's own role (IMDSv2) with `FALAK_SECRETS_PROVIDERS_ALLOW_INSTANCE_PROFILE=true`. It is off by
  default. The instance profile is only ever used to **assume a role of the organization's** (the role ARN is
  required), and the `ExternalId` sent is always the **organization's id** (any external ID entered is ignored). Pin
  it in the role's trust policy, so no other organization of the instance can assume the role:

  ```json
  {"Effect": "Allow", "Principal": {"AWS": "arn:aws:iam::<control plane account>:role/<control plane role>"},
   "Action": "sts:AssumeRole", "Condition": {"StringEquals": {"sts:ExternalId": "<your organization id>"}}}
  ```

## The HTTPS webhook contract

For anything without a native driver (an internal secrets service, a small adapter in front of another vault). Same
model as External Secrets' webhook provider.

```
GET {base URL}?ref=<name>
<header>: <value>                      (when configured)
Accept: application/json

200 {"value": "the secret value"}
```

- A linked secret's reference is `{base URL}/<name>`; `<name>` (everything after the base URL, URL-encoded) is sent as
  `ref`. The header is only ever sent to the base URL: a reference pointing elsewhere is refused.
- Any non-2xx answer is a failure (`404` reads as "not found", `401`/`403` as "access refused"); `value` must be a
  string, number or boolean. Redirects are not followed.
- The auth header can't be a header that controls the request itself (`Host`, `Content-Length`, `Transfer-Encoding`,
  `Connection` and the other hop-by-hop headers, `Cookie`, …).
- **Test connection** asks for `ref=__falak_connection_test__`: any `2xx` or `404` passes, `401`/`403` fails.

## Caching and outages

- A resolved value is cached **sealed** under the organization's data key (in the database, not the shared cache),
  bound to its provider and reference. Within the provider's cache TTL (default 5 minutes; 0 always asks) deployments
  use the cached value without calling the provider.
- After the TTL the provider is asked again. If it fails (unreachable, refused, not found), the **last good value** is
  used, a warning is logged (provider and error only), the provider's status turns *Failing*, and the
  `secrets.provider_unreachable` alert fires (once until the provider answers again, which sends
  `secrets.provider_recovered`).
- The last good value is a fallback for at most `FALAK_SECRETS_PROVIDERS_MAX_STALE_SECONDS` (default 24 hours) after
  it was fetched. Past that, deployments fail with the reason (and the alert) instead of using it.
- Within one deployment (or build, or job), once a provider failed its other secrets go straight to their last good
  values instead of waiting for the provider again.
- Answers larger than 1 MiB are cut off, and a value larger than 64 KiB (the most an environment variable value can be)
  is refused.
- With nothing cached, the deployment fails with the reason, e.g. `secret DB_PASS: Production Vault: HashiCorp Vault /
  OpenBao at vault.example.com is unreachable (or its answer was too large).` Messages never include credentials, tokens, values or the provider's
  response body.
- Logins (Vault AppRole / JWT client tokens, Infisical access tokens, AssumeRole sessions) are cached sealed until
  shortly before they expire. Changing a provider's settings bumps its config version: cached values and logins of the
  old settings are deleted and can no longer be opened.

## Watching for changes

A linked secret can be **watched**: every N minutes (at least 1) Falak asks the provider and compares the answer with
the last value it saw. Falak does not store that value for the comparison, only an HMAC of it under a key derived from
the organization's data key.

On a change, Falak:
1. records a **new version** (same reference, note *Changed upstream*) holding the new value as a sealed **snapshot**;
2. fires the `secrets.linked_changed` alert (names only);
3. per the secret's *When it changes* setting: does nothing more, **restarts** the processes of the services that use
   it, or **redeploys** them. The value reaches a service's environment when it is deployed, so choose *redeploy* for
   environment variables; *restart* suits apps that read the secret from the provider themselves on boot.

Polls run on the queue, one job per secret (never two at once for the same secret), at most
`FALAK_SECRETS_POLL_CONCURRENCY_PER_ORGANIZATION` (default 3) per organization at a time. A poll whose secret changed
while the provider answered (a rollback, a new reference) records nothing.

The first poll records the baseline (and a snapshot of the current version) without creating a version. A failed poll
alerts like any other provider failure and is retried at the next interval.

### Rolling back a linked secret

Each version keeps its reference and, when the watch saw it, the value it had (the snapshot). Rolling back to such a
version **pins** the secret to that value: deployments use the snapshot instead of asking the provider, and the watch
pauses. Saving the reference again (a new version) unpins it and makes the provider authoritative again. Rolling back
to a version without a snapshot only restores its reference.

## Network safety

- Endpoints must be `https://`; TLS is always verified (add a CA certificate for a private CA). Connect timeout 5 s,
  10 s in total, one retry on connection errors, `429` and `5xx`.
- Endpoints must resolve to public addresses. Private, loopback and CGNAT addresses need **both** the instance setting
  `FALAK_SECRETS_PROVIDERS_ALLOW_PRIVATE=true` (off by default: the control plane's own network stays out of reach)
  **and** the provider's **Allow private network** (Vault, OpenBao, Infisical, 1Password Connect and webhooks), checked
  when the provider is saved and again on every request. Link-local and cloud metadata addresses (`169.254.0.0/16`,
  `fd00:ec2::254`, `100.100.100.200`, site-local `fec0::/10`, …) are always refused, including when they are embedded
  in an IPv6 address (IPv4-mapped or -compatible, NAT64 `64:ff9b::/96`, 6to4 `2002::/16`). Numeric hosts other than a
  dotted quad (`2130706433`, `0x7f.1`) are refused.
- The check runs when the provider is saved and again before every request, and the request is pinned to the
  addresses checked (a DNS answer that changes in between can't redirect it).

## Permissions

`secrets.view` sees providers (names, types, status, non-secret settings); `secrets.providers.manage` (admins and
owners only: whoever edits an endpoint decides where credentials are sent) adds, edits, tests and deletes them.
Developers keep `secrets.manage` for secrets, including linking them to existing providers. Credentials are write-only:
they are sealed at rest and never returned by the UI or the API. When a setting that decides where they are sent
changes (URL, CA certificate, namespace, region, role, external ID, webhook header), every stored credential must be
entered again; otherwise an empty credential field keeps the stored one. A provider
used by linked secrets can't be deleted. The audit log records provider names and which settings changed, never values.
