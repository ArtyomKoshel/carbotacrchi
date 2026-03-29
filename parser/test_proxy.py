import time
from parsers.kbcha.client import KBChaClient
from parsers.kbcha.detail_parser import KBChaDetailParser

c = KBChaClient()
c.warmup()
time.sleep(3)
html = c.fetch_detail_page('28114277')
print('bot_check:', KBChaDetailParser.is_bot_check_page(html))
print('size:', len(html))
