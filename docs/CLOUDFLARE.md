# Cloudflare integration

Kiln can manage the DNS of your Cloudflare zones: it creates, updates and deletes the records of your services, names
new services under your own domain, and keeps everything working behind Cloudflare's proxy (orange cloud: DDoS
protection, edge cache, hidden server IP). Everything here works on Cloudflare's Free plan.

## Connect

1. **Settings → Integrations → Cloudflare → Create a token in Cloudflare.** The link opens Cloudflare's token form
   with the permissions filled in:

   | Permission | Why |
   |---|---|
   | Zone → DNS → Edit | create, update and delete records |
   | Zone → Zone → Read | find the zone and its account |
   | Zone → Zone Settings → Edit | check and fix SSL mode, minimum TLS, Always Use HTTPS |
   | Zone → Cache Purge → Purge | purge the cache after deploys (phase 3) |

   Under **Zone Resources** pick *Specific zone* and your domain.
2. Paste the token in Kiln and click **Connect**. Kiln verifies it with Cloudflare and stores it encrypted.
3. Click **Manage** next to the zone. New records are proxied (orange) by default; turn that off per zone, or per
   domain in the service's Networking tab.
4. Fix the zone settings Kiln flags: **SSL/TLS mode Full (strict)**, **minimum TLS 1.2**, **Always Use HTTPS off**
   (Caddy already redirects to HTTPS, and Cloudflare's redirect would block Let's Encrypt).

## What Kiln does in a managed zone

- **Records follow your domains.** Adding `shop.example.com` to a service creates one `A`/`AAAA` record per server it
  runs on (the load balancer of a load-balanced site), and for the `www` host when a www redirect is on. They change
  when the service moves servers and disappear when the domain or service is deleted. Template (compose) services'
  public domains get records too.
- **Only its own records.** Kiln tags each record it creates (`kiln:<id>` in the record's comment) and never changes
  anything else. If a name already has a record Kiln did not create, the domain shows a **conflict**: delete that
  record in Cloudflare, then click **Sync**.
- **Generated names under your zone.** Settings → Domains → *Cloudflare: example.com* (or "Generate names here" on the
  zone): new services get `service.example.com`; copies in other environments get `service-staging.example.com`.
  One level below the zone, so Cloudflare's free certificate covers it.
- **Certificates.** Servers keep getting Let's Encrypt certificates over HTTP-01, which Cloudflare passes through even
  when proxied (TLS-ALPN-01 is turned off for these names, since it cannot pass the proxy). With Full (strict),
  Cloudflare checks that certificate.
- **Real visitor IPs.** Servers trust Cloudflare's IP ranges and read `CF-Connecting-IP`, so access logs, IP allow /
  deny lists and rate limits see the visitor, not Cloudflare.
- **Never managed:** the panel and the agent API hosts. Agents authenticate with mutual TLS, which Cloudflare's proxy
  would terminate, so `agents.<your domain>` must stay DNS only.

## Limits on the Free plan

- Requests through the proxy are limited to **100 MB** (large uploads to Nextcloud or Paperless need DNS only).
- The free certificate covers `example.com` and `*.example.com`, not deeper names like `a.b.example.com`.
- Only HTTP(S) goes through the proxy; other ports need DNS only.

## Stop

- **Stop managing** a zone: Kiln stops changing it; the records it created stay, so services keep resolving.
- **Disconnect**: every zone of the connection is released the same way, and the token is deleted from Kiln.

## Coming next

Cloudflare Tunnel as a server ingress mode (no open ports), cache modes with purge after deploy, Under Attack mode,
origin lock-down, and "Sign in with Cloudflare" instead of pasting a token.
