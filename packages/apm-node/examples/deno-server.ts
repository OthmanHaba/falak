// deno run --allow-net --allow-env examples/deno-server.ts
// @ts-nocheck — Deno globals / npm: specifiers
import { start, withKilnRequest } from 'npm:@kiln/apm-node';

start();

Deno.serve({ port: 3000 }, withKilnRequest((req: Request) => new Response('hello from deno'), { route: '/' }));
