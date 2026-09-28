import { generateKeyPairSync } from 'node:crypto';
import { createServer, type IncomingMessage, type Server, type ServerResponse } from 'node:http';

/**
 * A tiny stand-in for github.com + api.github.com, enough for the GitHub App manifest flow end to end:
 * manifest form POST → redirect with a code, code conversion, installation page → setup URL, installation
 * details, installation tokens and repository listing. Point Kiln at it with GITHUB_URL and GITHUB_API_URL.
 */
export function startFakeGitHub(port: number): Promise<Server> {
    const pem = generateKeyPairSync('rsa', { modulusLength: 2048 }).privateKey.export({ type: 'pkcs1', format: 'pem' }).toString();
    const apps = new Map<string, { manifest: Record<string, unknown>; owner: string | null }>();
    let lastApp: { manifest: Record<string, unknown>; owner: string | null } | null = null;

    const read = (req: IncomingMessage) =>
        new Promise<string>((resolve) => {
            let body = '';
            req.on('data', (chunk) => (body += chunk));
            req.on('end', () => resolve(body));
        });
    const json = (res: ServerResponse, status: number, body: unknown) => {
        res.writeHead(status, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify(body));
    };
    const redirect = (res: ServerResponse, location: string) => {
        res.writeHead(302, { Location: location });
        res.end();
    };

    const server = createServer(async (req, res) => {
        const url = new URL(req.url ?? '/', `http://127.0.0.1:${port}`);
        const path = url.pathname;
        const state = url.searchParams.get('state') ?? '';

        // github.com: "Create GitHub App" for a personal account or an organization.
        const create = path.match(/^\/(?:organizations\/([^/]+)\/)?settings\/apps\/new$/);
        if (req.method === 'POST' && create) {
            const manifest = JSON.parse(new URLSearchParams(await read(req)).get('manifest') ?? '{}') as Record<string, unknown>;
            const code = `code-${apps.size + 1}`;
            apps.set(code, { manifest, owner: create[1] ?? null });
            return redirect(res, `${String(manifest.redirect_url)}?code=${code}&state=${encodeURIComponent(state)}`);
        }

        // api.github.com: manifest code conversion.
        const conversion = path.match(/^\/app-manifests\/([^/]+)\/conversions$/);
        if (req.method === 'POST' && conversion) {
            const app = apps.get(conversion[1]);
            if (!app) return json(res, 404, { message: 'Not Found' });
            apps.delete(conversion[1]);
            lastApp = app;
            const owner = app.owner ?? 'ada-e2e';
            return json(res, 201, {
                id: 90210,
                slug: 'kiln-e2e',
                name: String(app.manifest.name),
                owner: { login: owner, type: app.owner ? 'Organization' : 'User' },
                html_url: `http://127.0.0.1:${port}/apps/kiln-e2e`,
                client_id: 'Iv1.e2e',
                client_secret: 'e2e-client-secret',
                webhook_secret: 'e2e-webhook-secret',
                pem,
            });
        }

        // github.com: install the app → GitHub's redirect to the app's setup URL.
        if (req.method === 'GET' && /^\/apps\/[^/]+\/installations\/new$/.test(path)) {
            const setup = String(lastApp?.manifest.setup_url ?? '');
            return redirect(res, `${setup}?installation_id=4711&setup_action=install&state=${encodeURIComponent(state)}`);
        }

        const installation = path.match(/^\/app\/installations\/(\d+)(\/access_tokens)?$/);
        if (installation) {
            if (req.method === 'DELETE') {
                res.writeHead(204);
                return res.end();
            }
            if (installation[2]) return json(res, 201, { token: 'ghs_e2e', expires_at: new Date(Date.now() + 3600_000).toISOString() });
            const account = lastApp?.owner ?? 'ada-e2e';
            return json(res, 200, {
                id: Number(installation[1]),
                account: { login: account },
                target_type: lastApp?.owner ? 'Organization' : 'User',
            });
        }

        if (req.method === 'GET' && path === '/installation/repositories') {
            const owner = lastApp?.owner ?? 'ada-e2e';
            const repositories = ['storefront', 'marketing-site', 'api'].map((name, i) => ({
                full_name: `${owner}/${name}`,
                default_branch: i === 1 ? 'trunk' : 'main',
                private: i !== 1,
                clone_url: `http://127.0.0.1:${port}/${owner}/${name}.git`,
                html_url: `http://127.0.0.1:${port}/${owner}/${name}`,
            }));
            return json(res, 200, { total_count: repositories.length, repositories });
        }

        json(res, 404, { message: 'Not Found (fake GitHub)' });
    });

    return new Promise((resolve) => server.listen(port, '127.0.0.1', () => resolve(server)));
}
