import json
data = json.load(open(r"c:\Users\Archi\Downloads\logs.1774692624034.json"))
keywords = ["parser","kbcha","python","module","error","fail","traceback","exited","fatal","spawned","pip","response keys","top keys","sample","no listings","loaded","extract","nested","structure"]
for e in data:
    msg = e.get("message", "")
    sev = e.get("severity", "")
    if any(k in msg.lower() for k in keywords):
        print(f"[{sev}] {msg[:400]}")
