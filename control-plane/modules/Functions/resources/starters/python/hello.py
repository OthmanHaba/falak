# /// script
# dependencies = ["fastapi"]
# ///
from datetime import datetime, timezone

from fastapi import FastAPI

app = FastAPI()


@app.get("/")
def index():
    return {"message": "Hello from Falak!", "time": datetime.now(timezone.utc).isoformat()}


@app.get("/hello/{name}")
def hello(name: str):
    return {"message": f"Hello, {name}!"}
