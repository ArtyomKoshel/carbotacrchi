"""Versioned signing parameters from che168's public mobile JavaScript.

The packaged profile contains public frontend constants, not account credentials.
No JavaScript is executed; unexpected profiles fail closed.
"""

from __future__ import annotations

import base64
import hashlib
import json
from dataclasses import dataclass, field
from importlib.resources import files

from cryptography.hazmat.primitives import padding
from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes


@dataclass(frozen=True)
class Signer:
    appid: str
    version: str
    bundle_sha256: str
    salt: str = field(repr=False)

    @classmethod
    def bundled(cls) -> Signer:
        data = json.loads(files(__package__).joinpath("signing-profile.json").read_text(encoding="utf-8"))
        encoded = data["encoded_key"]
        order = data["permutation"]
        if sorted(order) != list(range(len(encoded))):
            raise ValueError("invalid public signing profile")
        key = base64.b64decode("".join(encoded[i] for i in order), validate=True)
        # Required by the public frontend protocol, not used to encrypt stored secrets.
        decryptor = Cipher(algorithms.AES(key), modes.ECB()).decryptor()  # noqa: S305
        plain = decryptor.update(base64.b64decode(data["encrypted_salt"], validate=True))
        plain += decryptor.finalize()
        unpadder = padding.PKCS7(128).unpadder()
        salt = (unpadder.update(plain) + unpadder.finalize()).decode("utf-8")
        return cls(data["appid"], data["version"], data["bundle_sha256"], salt)

    def sign(self, specific: dict[str, str]) -> dict[str, str]:
        params = {
            "_appid": self.appid,
            "v": self.version,
            "_subappid": "",
            "deviceid": "",
            "userid": "",
            "s_pid": "0",
            "s_cid": "0",
            **specific,
        }
        canonical = "".join(key + params[key] for key in sorted(params))
        # MD5 is required by the site's protocol, not used for password/security storage.
        params["_sign"] = hashlib.md5(
            (self.salt + canonical + self.salt).encode(), usedforsecurity=False
        ).hexdigest()
        return params
