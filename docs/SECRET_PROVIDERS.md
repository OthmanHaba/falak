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
- **AWS instance profile.** On an instance where the control plane runs on EC2, the operator can let AWS providers use
  the control plane's own role (IMDSv2) with `FALAK_SECRETS_PROVIDERS_ALLOW_INSTANCE_PROFILE=true`. It is off by
  default: every organization on the instance would read with that role.

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
- **Test connection** asks for `ref=__falak_connection_test__`: any `2xx` or `404` passes, `401`/`403` fails.

## Caching and outages

- A resolved value is cached **sealed** under the organization's data key (in the database, not the shared cache),
  bound to its provider and reference. Within the provider's cache TTL (default 5 minutes; 0 always asks) deployments
  use the cached value without calling the provider.
- After the TTL the provider is asked again. If it fails (unreachable, refused, not found), the **last good value** is
  used, a warning is logged (provider and error only), the provider's status turns *Failing*, and the
  `secrets.provider_unreachable` alert fires (once until the provider answers again, which sends
  `secrets.provider_recovered`).
- With nothing cached, the deployment fails with the reason, e.g. `secret DB_PASS: Production Vault: HashiCorp Vault /
  OpenBao at vault.example.com is unreachable.` Messages never include credentials, tokens, values or the provider's
  response body.
- Logins (Vault AppRole / JWT client tokens, Infisical access tokens, AssumeRole sessions) are cached sealed until
  shortly before they expire. Editing a provider's settings starts over.

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
- Endpoints must resolve to public addresses. **Allow private network** (Vault, OpenBao, Infisical, 1Password Connect
  and webhooks) lets a self-hosted provider on a private, loopback or CGNAT address be used; operators can forbid it
  instance-wide with `FALAK_SECRETS_PROVIDERS_ALLOW_PRIVATE=false`. Link-local and cloud metadata addresses
  (`169.254.0.0/16`, `fd00:ec2::254`, `100.100.100.200`, …) are always refused.
- The check runs when the provider is saved and again before every request, and the request is pinned to the
  addresses checked (a DNS answer that changes in between can't redirect it).

## Permissions

`secrets.view` sees providers (names, types, status, non-secret settings); `secrets.manage` adds, edits, tests and
deletes them. Credentials are write-only: they are sealed at rest and never returned by the UI or the API. A provider
used by linked secrets can't be deleted. The audit log records provider names and which settings changed, never values.
