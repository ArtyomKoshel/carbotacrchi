"""
Тест-скрипт: вызываем Encar API для 10 машин и собираем ВСЕ даты из каждого
эндпоинта, чтобы понять что где лежит.

Выводит таблицу:
  lot_id | FormYear | manage.registDateTime | record.firstDate | inspection.firstRegistrationDate | inspection.registrationDate
"""
import sys
import os
import json
import time

sys.path.insert(0, os.path.dirname(__file__))

from parsers.encar.client import EncarClient
from config import Config

config = Config()
client = EncarClient(config)

SAMPLE_SIZE = 10

print("Fetching search results...")
search_data = client.search("(And.Hidden.N._.CarType.A.)", offset=0, count=SAMPLE_SIZE)
results = search_data.get("SearchResults", [])[:SAMPLE_SIZE]
print(f"Got {len(results)} lots\n")

# Header
print("-" * 140)
print(f"{'ID':<12} {'Make':<12} {'Model':<14} {'FormYear':<10} {'manage.registDT':<22} {'record.firstDate':<18} {'insp.firstRegDate':<18} {'insp.registDate':<18}")
print("-" * 140)

for item in results:
    vid = str(item["Id"])
    make = item.get("Manufacturer", "?")[:10]
    model = item.get("Model", "?")[:12]
    form_year = item.get("FormYear", "")

    # Detail API
    manage_regist_dt = ""
    try:
        detail = client.detail(vid)
        manage = detail.get("manage", {})
        manage_regist_dt = manage.get("registDateTime", "")
        if manage_regist_dt:
            manage_regist_dt = manage_regist_dt[:19]  # trim to datetime
    except Exception as e:
        manage_regist_dt = f"ERR:{e}"

    time.sleep(0.3)

    # Record API
    record_first_date = ""
    try:
        rec = client.record(vid)
        if rec:
            record_first_date = rec.get("firstDate", "") or ""
    except Exception as e:
        record_first_date = f"ERR:{e}"

    time.sleep(0.3)

    # Inspection API
    insp_first_reg = ""
    insp_regist_date = ""
    try:
        insp = client.inspection(vid)
        if insp:
            master = insp.get("master", {})
            insp_detail = master.get("detail", {})
            insp_first_reg = insp_detail.get("firstRegistrationDate", "") or ""
            insp_regist_date = master.get("registrationDate", "") or ""
            if insp_regist_date:
                insp_regist_date = insp_regist_date[:10]
    except Exception as e:
        insp_first_reg = f"ERR"

    time.sleep(0.3)

    print(f"{vid:<12} {make:<12} {model:<14} {form_year:<10} {manage_regist_dt:<22} {record_first_date:<18} {insp_first_reg:<18} {insp_regist_date:<18}")

print("-" * 140)

# Legend
print("""
ЛЕГЕНДА:
  FormYear              — из Search API. YYYYMM формат. Что это? (год модели? год регистрации?)
  manage.registDateTime — из Detail API, секция "manage". Что это? (дата объявления? дата регистрации авто?)
  record.firstDate      — из Record API (история аварий/владений). Что это? (первая регистрация авто)
  insp.firstRegDate     — из Inspection API → master.detail.firstRegistrationDate. (первая регистрация авто из отчёта)
  insp.registDate       — из Inspection API → master.registrationDate. (дата проведения техосмотра)

СРАВНИВАЯ ЗНАЧЕНИЯ:
  - Если FormYear ≈ record.firstDate ≈ insp.firstRegDate → все три = дата первой регистрации авто
  - Если manage.registDateTime сильно отличается → это дата объявления
  - insp.registDate — скорее всего дата техосмотра (отличается от остальных)
""")

client.close()
