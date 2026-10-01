# /// script
# dependencies = ["fastapi", "psycopg[binary]"]
# ///
# A small notes API on Postgres. Set DATABASE_URL in Variables, e.g. ${{ postgres.DATABASE_URL }}
# (connect a database on the canvas to get the reference).
import os

import psycopg
from fastapi import FastAPI, HTTPException
from psycopg.rows import dict_row
from pydantic import BaseModel

app = FastAPI()
_ready = False


async def db():
    global _ready
    conn = await psycopg.AsyncConnection.connect(os.environ.get("DATABASE_URL", ""), row_factory=dict_row, autocommit=True)
    # Created on first use (not at import time, so the function deploys before DATABASE_URL is set).
    if not _ready:
        await conn.execute("create table if not exists notes (id serial primary key, body text not null, created_at timestamptz default now())")
        _ready = True
    return conn


class Note(BaseModel):
    body: str


@app.get("/")
async def index():
    async with await db() as conn:
        return await (await conn.execute("select now() as now, version() as version")).fetchone()


@app.get("/notes")
async def notes():
    async with await db() as conn:
        return await (await conn.execute("select * from notes order by id desc limit 50")).fetchall()


@app.get("/notes/{note_id}")
async def note(note_id: int):
    async with await db() as conn:
        row = await (await conn.execute("select * from notes where id = %s", (note_id,))).fetchone()
    if row is None:
        raise HTTPException(404, "not found")
    return row


@app.post("/notes", status_code=201)
async def create(note: Note):
    async with await db() as conn:
        return await (await conn.execute("insert into notes (body) values (%s) returning *", (note.body,))).fetchone()


@app.delete("/notes/{note_id}", status_code=204)
async def delete(note_id: int):
    async with await db() as conn:
        cur = await conn.execute("delete from notes where id = %s", (note_id,))
    if cur.rowcount == 0:
        raise HTTPException(404, "not found")
