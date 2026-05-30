const fs = require('fs');
const data = JSON.parse(fs.readFileSync('D:/work/carbot/docs/model_raw.json', 'utf8'));
const models = data.map(d => d.model);

const fuel = new Set(['디젤','가솔린','gasoline','휘발유','lpi','lpe','lpg','바이퓨얼','하이브리드','hev','phev','ev','전기','bev','수소','fcev','mhev','cng','hdi','tce','dci','e-tech']);
const drive = new Set(['2wd','fwd','4wd','awd','4x4','콰트로','quattro','xdrive','4matic','all4','sdrive','rwd','s-awc','4모션','e-htrac','htrac','e-awd','e-four','사륜구동','전륜구동','후륜구동']);
const engFam = new Set(['gdi','t-gdi','tgdi','mpi','mpfi','tfsi','tsi','fsi','tdi','crdi','vgt','tci','e-vgt','fhev','hev','lpi','lpe','bev','4matic','블루텍','gte','lpli','4tronic','터보','vvt','dci','e-tech','hdi','skyactiv','skyactiv-g','skyactiv-d','vtec','cvvt','dohc','sohc','tce','mhev','hemi','cvs','cvt','cvx']);
const specCodes = new Set(['d','amg','35','40','45','50','55','60','b3','b4','b5','b6','b8','t4','t5','t6','t8','d2','d3','d4','d5','sdv6','sdv8','td4','td6','si4','si6','p250','p300','p400','p530','d180','d200','d250','d300','re16','re19','re20']);
const prefixWords = new Set(['더','뉴','올','베리','어메이징','디','신형','올뉴','넥스트','라이즈','기아','그랜드','뷰티풀','g4','어메이징뉴']);
const body = new Set(['쿠페','컨버터블','카브리올레','로드스터','왜건','해치백','세단','픽업','밴','리무진','하이리무진','그란쿠페','스포트백','카브리오','suv','5도어','4도어','2도어','3도어','투어링']);
const seats = /^\d{1,2}인승$/;
const genLabel = /^\d+세대$/;
const chassisPat = /^[A-Z]{1,3}\d{1,3}[A-Z]?$/i;
const engVolPat = /^\d+\.\d+[tdl]?$/i;
const knownTrims = ['스탠다드 레인지 플러스','스탠다드 레인지','노블레스 스페셜','노블레스 라이트','시그니처 블랙','파이니스트 에디션','스페셜 에디션','퍼포먼스 에디션','블랙 에디션','퍼스트 에디션','컴포트 에디션','플래티넘 플러스','밸류 플러스','테크노 플러스','hse 다이나믹','hse dynamic','m 스포츠 프로','m 스포츠 플러스','m 스포츠 패키지','m 스포츠','amg line','amg 라인','f sport','f 스포츠','r-design','r-디자인','얼티메이트 브라이트','얼티메이트 다크','온라인 익스클루시브','디자인 퓨어 엑셀런스','럭셔리 플러스','럭셔리 라인','sport line','스포트라인','프레스티지','프리미엄','노블레스','시그니처','캘리그래피','인스퍼레이션','익스클루시브','르블랑','모던','스마트','스타일','럭셔리','디럭스','스탠다드','트렌디','프리미어','마스터즈','마스터피스','플래티넘','그래비티','어드밴스드','아너스','하이클래스','트렌드','스포츠','아웃도어','리미티드','스페셜','베이직','어반','액티브','레드라인','어드벤처','vip','헤리티지','인스파이어','어스','re plus','le plus','re','le','se','pe','high','mid','인텐스','라이프','퍼스트','ls','lt','ltz','activ','premier','xline','어드밴티지','퓨어','이그제큐티브','컴포트','아방가르드','엘레강스','s-line','dynamic','technik','progressive','프로그레시브','momentum','inscription','core','ultra','plus','슈프림','타쿠미','hse','svr','svx','autobiography','오토바이오그래피','carrera','카레라','gts','gt3','gt3 rs','gt4','targa','타르가','jcw','클래식','에센셜','페이버드','롱레인지','퍼포먼스','마스터','볼드','프라임','gt','초이스','st-라인','rvip','카고','네오','프리미에르','에센스','아이코닉','브릴리언트','엘리트','최고급형','고급형','기본형','미드나이트 블랙','퍼펙트 블랙','블랙라벨','플래티넘','n line','n 라인','n라인','gt-line','gt 라인','gt라인','x-line','x 라인','엑스라인','vx','dx','lx','ix','ex','mx','rx5','rx7','cx7','cx5','gt','kx','tx','ax','dx','rt'];

function simulate(raw) {
  let s = raw
    .replace(/\s*\([^)]*(?:렌터카|장애인용|택시형|수출형|캠핑카|영업용)[^)]*\)\s*/g, ' ')
    .replace(/\([A-Za-z0-9\-]+\)/g, ' ')
    .replace(/\s{2,}/g, ' ').trim();

  // Sort trims by length desc and strip
  const sortedTrims = [...knownTrims].sort((a, b) => b.length - a.length);
  for (const t of sortedTrims) {
    const re = new RegExp(t.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&'), 'ig');
    s = s.replace(re, ' ').replace(/\s{2,}/g, ' ').trim();
  }

  const tokens = s.split(/\s+/);
  const kept = [];
  for (const tok of tokens) {
    const lower = tok.toLowerCase();
    if (!tok) continue;
    if (fuel.has(lower)) continue;
    if (drive.has(lower)) continue;
    if (engFam.has(lower)) continue;
    if (specCodes.has(lower)) continue;
    if (prefixWords.has(lower)) continue;
    if (body.has(lower)) continue;
    if (seats.test(tok)) continue;
    if (genLabel.test(tok)) continue;
    if (chassisPat.test(tok) && tok.length <= 6) continue;
    if (engVolPat.test(lower)) continue;
    if (/^\d+$/.test(tok) && parseInt(tok) < 200) continue;
    if (/^\(/.test(tok) || /\)$/.test(tok)) continue;
    kept.push(tok);
  }
  return kept.join(' ').trim();
}

const remainCounts = {};
for (const m of models) {
  const r = simulate(m);
  if (r) {
    remainCounts[r] = (remainCounts[r] || 0) + 1;
  }
}

const sorted = Object.entries(remainCounts).sort((a, b) => b[1] - a[1]);
console.log('Unresolved (' + sorted.length + ' unique, ' + sorted.reduce((s,[,c])=>s+c,0) + ' total):');
sorted.slice(0, 120).forEach(([r, c]) => console.log(c.toString().padStart(4) + '  ' + r));
