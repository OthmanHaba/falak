// deno run --allow-net --allow-env examples/deno-server.ts
// @ts-nocheck — Deno globals / npm: specifiers
import { start, withFalakRequest } from 'npm:@falak/apm-node';

start();

Deno.serve({ port: 3000 }, withFalakRequest((req: Request) => new Response('hello from deno'), { route: '/' }));
