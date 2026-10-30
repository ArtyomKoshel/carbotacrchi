"""python -m collector.che168 --output <directory> [--resume]. No production DB access."""

from __future__ import annotations

import argparse
import hashlib
import json
import signal
import uuid
from importlib import import_module
from pathlib import Path
from types import FrameType

from collector.adapters.che168.api import Che168Api, CollectionError, CurlTransport, RotationPolicy
from collector.adapters.che168.pipeline import run
from collector.adapters.che168.signing import Signer
from collector.adapters.che168.store import FileStore


def positive(value: str) -> int:
    number = int(value)
    if number < 1:
        raise argparse.ArgumentTypeError("must be positive")
    return number


def nonnegative(value: str) -> int:
    number = int(value)
    if number < 0:
        raise argparse.ArgumentTypeError("must not be negative")
    return number


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--resume", action="store_true")
    parser.add_argument("--env-file", type=Path, help="Explicit local dotenv file; existing environment wins")
    parser.add_argument("--max-pages", type=positive, default=10)
    parser.add_argument("--max-cars", type=positive, default=200)
    parser.add_argument("--pagesize", type=int, choices=range(1, 21), default=20)
    parser.add_argument("--max-requests", type=positive, default=500)
    parser.add_argument("--max-seconds", type=positive, default=1800)
    parser.add_argument("--delay", type=float, default=3.0)
    parser.add_argument(
        "--rotate-every-pages", type=nonnegative, default=2, help="0 disables scheduled rotation"
    )
    parser.add_argument("--max-rotations", type=nonnegative, default=8)
    parser.add_argument("--max-challenge-rotations", type=nonnegative, default=3)
    args = parser.parse_args(argv)
    if not 1 <= args.delay <= 300:
        parser.error("--delay must be between 1 and 300 seconds")
    transport, store = None, None
    stopped = False

    def stop(_signum: int, _frame: FrameType | None) -> None:
        nonlocal stopped
        stopped = True

    previous = {sig: signal.signal(sig, stop) for sig in (signal.SIGINT, signal.SIGTERM)}
    try:
        if args.env_file:
            if not args.env_file.is_file():
                raise CollectionError("env_file_missing")
            import_module("dotenv").load_dotenv(args.env_file, override=False)
        signer = Signer.bundled()
        config = json.dumps(
            {
                "schema": 1,
                "mapping": 2,
                "scope": "all_makes_all_china",
                "pagesize": args.pagesize,
                "bundle_sha256": signer.bundle_sha256,
            },
            sort_keys=True,
        )
        fingerprint = hashlib.sha256(config.encode()).hexdigest()
        transport = CurlTransport("che" + uuid.uuid4().hex[:16])
        store = FileStore(args.output, fingerprint=fingerprint, resume=args.resume)
        api = Che168Api(
            transport.request,
            signer,
            delay=args.delay,
            max_requests=args.max_requests,
            max_seconds=args.max_seconds,
            rotate=transport.rotate,
            rotation=RotationPolicy(
                args.rotate_every_pages, args.max_rotations, args.max_challenge_rotations
            ),
        )
        summary = run(
            api,
            store,
            max_pages=args.max_pages,
            max_cars=args.max_cars,
            pagesize=args.pagesize,
            stopped=lambda: stopped,
            progress=lambda event: print(json.dumps(event), flush=True),
        )
        print(json.dumps(summary), flush=True)
        if summary["status"] == "interrupted":
            return 130
        return 0 if summary["status"] in ("page_limit", "car_limit", "api_end_reached") else 1
    except CollectionError as exc:
        print(json.dumps({"status": exc.kind}), flush=True)
        return 1
    except ImportError:
        print(json.dumps({"status": "install_che168_extra"}), flush=True)
        return 2
    finally:
        if transport:
            transport.close()
        if store:
            store.close()
        for sig, handler in previous.items():
            signal.signal(sig, handler)


if __name__ == "__main__":
    raise SystemExit(main())
