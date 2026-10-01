/**
 * Ambient types the editor loads for Bun + Hono functions: the parts of Hono's and Bun's APIs a function uses most,
 * so autocomplete and type checks work without shipping their full type packages to the browser.
 */
export const BUN_HONO_TYPES = `
declare module 'hono' {
    type Handler = (c: Context, next: Next) => Response | Promise<Response | void> | void;
    type Next = () => Promise<void>;
    export type MiddlewareHandler = (c: Context, next: Next) => Promise<Response | void>;

    export interface HonoRequest {
        param(): Record<string, string>;
        param(name: string): string;
        query(): Record<string, string>;
        query(name: string): string | undefined;
        queries(name: string): string[] | undefined;
        header(): Record<string, string>;
        header(name: string): string | undefined;
        json<T = any>(): Promise<T>;
        text(): Promise<string>;
        arrayBuffer(): Promise<ArrayBuffer>;
        formData(): Promise<FormData>;
        parseBody<T = Record<string, string | File>>(): Promise<T>;
        readonly url: string;
        readonly method: string;
        readonly path: string;
        readonly raw: Request;
    }

    export interface Context {
        req: HonoRequest;
        env: any;
        json(object: unknown, status?: number, headers?: Record<string, string>): Response;
        text(text: string, status?: number, headers?: Record<string, string>): Response;
        html(html: string, status?: number, headers?: Record<string, string>): Response;
        body(data: BodyInit | null, status?: number, headers?: Record<string, string>): Response;
        redirect(location: string, status?: number): Response;
        notFound(): Response;
        header(name: string, value: string): void;
        status(status: number): void;
        set(key: string, value: unknown): void;
        get<T = any>(key: string): T;
        var: Record<string, any>;
        res: Response;
    }

    export class Hono {
        constructor(options?: { strict?: boolean });
        get(path: string, ...handlers: Handler[]): this;
        post(path: string, ...handlers: Handler[]): this;
        put(path: string, ...handlers: Handler[]): this;
        patch(path: string, ...handlers: Handler[]): this;
        delete(path: string, ...handlers: Handler[]): this;
        options(path: string, ...handlers: Handler[]): this;
        all(path: string, ...handlers: Handler[]): this;
        on(method: string | string[], path: string, ...handlers: Handler[]): this;
        use(...handlers: (Handler | MiddlewareHandler)[]): this;
        use(path: string, ...handlers: (Handler | MiddlewareHandler)[]): this;
        route(path: string, app: Hono): this;
        basePath(path: string): Hono;
        notFound(handler: (c: Context) => Response | Promise<Response>): this;
        onError(handler: (err: Error, c: Context) => Response | Promise<Response>): this;
        fetch(request: Request, env?: unknown): Response | Promise<Response>;
        request(input: string | Request, init?: RequestInit): Promise<Response>;
    }

    export class HTTPException extends Error {
        constructor(status?: number, options?: { message?: string; res?: Response });
        readonly status: number;
        getResponse(): Response;
    }
}

declare module 'hono/*' {
    const value: any;
    export = value;
}

declare module 'bun' {
    interface SQL {
        <T = any>(strings: TemplateStringsArray, ...values: unknown[]): Promise<T[]> & { values(): Promise<unknown[][]> };
        (value: unknown): unknown;
        begin<T>(fn: (tx: SQL) => Promise<T>): Promise<T>;
        unsafe<T = any>(query: string, params?: unknown[]): Promise<T[]>;
        close(): Promise<void>;
    }
    /** Postgres client configured from DATABASE_URL. */
    export const sql: SQL;
    export const SQL: new (url?: string | Record<string, unknown>) => SQL;
    export const redis: any;
    export const RedisClient: new (url?: string) => any;
    export const password: { hash(password: string): Promise<string>; verify(password: string, hash: string): Promise<boolean> };
    export function file(path: string): Blob & { json(): Promise<any>; text(): Promise<string>; exists(): Promise<boolean> };
    export function sleep(ms: number): Promise<void>;
    export const env: Record<string, string | undefined>;
}

declare const process: { env: Record<string, string | undefined>; exit(code?: number): never; uptime(): number; version: string };
declare const Bun: { env: Record<string, string | undefined>; version: string; sleep(ms: number): Promise<void> };
declare const Deno: { env: { get(key: string): string | undefined; toObject(): Record<string, string> }; version: { deno: string }; [key: string]: any };

declare module 'postgres' {
    interface Sql {
        <T = any>(strings: TemplateStringsArray, ...values: unknown[]): Promise<T[]>;
        (value: unknown): unknown;
        begin<T>(fn: (sql: Sql) => Promise<T>): Promise<T>;
        unsafe<T = any>(query: string, params?: unknown[]): Promise<T[]>;
        end(): Promise<void>;
    }
    export default function postgres(url?: string, options?: Record<string, unknown>): Sql;
}
`;
