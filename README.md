# Kotchasan vs PHP Frameworks — Benchmark Suite

ชุดเครื่องมือสำหรับ benchmark **Kotchasan** เทียบกับ PHP framework ยอดนิยม
โดย**วัดผลจริงด้วย HTTP load generator** บนสภาพแวดล้อมที่ควบคุมด้วย Docker
และ**ทำซ้ำได้ 100%** (ทุกเวอร์ชันถูก pin ตายตัว ข้อมูลชุดเดียวกัน สคริปต์เดียวกัน)

## เฟรมเวิร์กที่ทดสอบ

| แอป | เฟรมเวิร์ก | ที่มาของเวอร์ชัน |
|---|---|---|
| plain-php | PHP เปล่า (ไม่มีเฟรมเวิร์ก) — baseline อ้างอิง | image `php:8.4-fpm-bookworm` |
| kotchasan | **Kotchasan** | สำเนาพินจาก repo จริง (ดู `apps/kotchasan/Kotchasan/SOURCE.md`) |
| slim | Slim | `composer.lock` |
| codeigniter | CodeIgniter | `composer.lock` |
| symfony | Symfony (skeleton + framework-bundle) | `composer.lock` |
| laravel | Laravel (ค่า default + config/route/view cache) | `composer.lock` |

เวอร์ชันจริงที่ใช้ในแต่ละรันถูกบันทึกอัตโนมัติใน `meta.json` ของผลรันนั้น ๆ

## สถาปัตยกรรม

```
                 ┌──────────────┐
   autocannon ──▶│ nginx (1)    │  1 nginx, 6 server blocks (port 18081–18086)
   (host)        └──────┬───────┘
        ┌─────────┬─────┼──────┬─────────┬─────────┐
        ▼         ▼     ▼      ▼         ▼         ▼
     plain-php kotchasan slim codeigniter symfony laravel
     (php-fpm 8.4 image เดียวกัน, php.ini/php-fpm.conf เดียวกัน, 16 workers เท่ากัน)
        └─────────┴─────┬──────┴─────────┴─────────┘
                        ▼
                  MySQL 8.4 (world 10,000 แถว + fortune 12 แถว, seed คงที่)
```

## สถานการณ์ทดสอบ (ตามแนวทาง TechEmpower)

| Endpoint | สิ่งที่วัด |
|---|---|
| `GET /plaintext` | ต้นทุนต่อ request ของเฟรมเวิร์ก (bootstrap, routing, response) |
| `GET /json` | + การสร้าง JSON response |
| `GET /db` | + query ฐานข้อมูล 1 แถวสุ่ม |
| `GET /queries?n=20` | + query 20 แถวสุ่ม (prepared statement ใช้ซ้ำ) |
| `GET /fortunes` | + template + HTML escaping + UTF-8 |
| `GET /info` | น้ำหนักต่อ request: peak memory, จำนวนไฟล์ที่โหลด (ไม่ได้อยู่ในลูปวัด) |

ข้อตกลงสำคัญ: **ทุกแอปใช้ raw PDO + SQL เดียวกันเป๊ะ** (เห็นใน `infra/php/bench_shared.php`
ซึ่ง copy ไปยังทุก container แบบ byte-identical) เพื่อให้ตัวเลขสะท้อน
**overhead ของ request pipeline ของเฟรมเวิร์กเท่านั้น** ไม่ปนกับความเร็ว ORM ของแต่ละค่าย

## หลักการให้ผลเป็นธรรม

1. **สภาพแวดล้อมเดียวกันเป๊ะ** — ทุกแอปใช้ PHP image เดียวกัน, php.ini เดียวกัน
   (opcache เปิด, validate_timestamps=0, JIT ปิด), php-fpm pool เดียวกัน (static 16 workers),
   nginx block เดียวกันยกเว้น upstream, MySQL เดียวกัน
2. **Production config** — Laravel/Symfony/CI4 รันแบบ production + cache config/route/view แล้ว
   (ตามวิธี deploy จริง), DEBUG ปิดหมด
3. **ตรวจความถูกต้องก่อนวัดเสมอ** — `make verify` ยิงทุก endpoint ของทุกแอป ตรวจ
   status/content-type/รูปร่างข้อมูล และ fortunes HTML ต้อง **byte-identical ข้ามทุกเฟรมเวิร์ก**
   (พิสูจน์ว่าทุกแอปทำงานเหมือนกันจริง) — ถ้าไม่ผ่าน benchmark จะไม่รัน
4. **สถิติแบบ interleave** — วัด `RUNS`(=5) รัน × `DURATION`(=10) วินาที ต่อ app×scenario
   โดยวนสลับ run→scenario→app เพื่อให้สภาพเครื่อง (thermal/cache/background load)
   กระทบทุกเฟรมเวิร์กเท่ากัน และรายงานค่า **median**
5. **Warmup ก่อนวัด** — อุ่นทั้งฝั่ง PHP (opcache) และ MySQL (buffer pool) ก่อนเก็บตัวเลข
6. **ไม่มี noise จาก logging** — access log ปิด, gzip ปิด, error ไป stderr

## ผลลัพธ์ (จากเครื่องที่สร้างชุดทดสอบนี้)

- **หน้าเว็บรายงาน (แนะนำ)**: เปิด `index.html` — อ่านข้อมูลสดจากไฟล์ดิบใน `results/raw/` โดยตรง
  เลือก/เทียบผลรันได้, พร้อมใช้บน GitHub Pages (ดูหัวข้อถัดไป)
- รายงาน snapshot ตอนเผยแพร่ครั้งแรก: `results/REPORT.md` / `results/REPORT.html`
- ผลดิบทุกรัน: `results/raw/<timestamp>/` (มี `meta.json` fingerprint สภาพแวดล้อมครบถ้วน, `summary.csv`, raw JSON รายรัน)

สรุปความเร็ว (RPS, median จาก 5 runs × 10s, 128 connections; AMD Ryzen 5 7535HS, PHP 8.4, MySQL 8.4):

| แอป | plaintext | json | db | queries×20 | fortunes | % ของ PHP เปล่า (plaintext) |
|---|---|---|---|---|---|---|
| PHP เปล่า (baseline) | 22,194 | 22,296 | 11,832 | 2,926 | 10,741 | 100% |
| **Kotchasan 7.0** | **18,621** | **18,468** | **10,101** | **2,961** | **9,356** | **84–89%** |
| Slim 4.15 | 13,131 | 13,144 | 7,991 | 2,696 | 7,536 | 59–65% |
| Symfony 8.1 | 6,314 | 6,256 | 4,779 | 2,167 | 4,523 | 28–33% |
| CodeIgniter 4.7 | 3,230 | 3,261 | 2,743 | 1,597 | 2,684 | 14–16% |
| Laravel 13.30 | 2,888 | 2,896 | 2,464 | 1,467 | 2,363 | 13–15% |

น้ำหนักต่อ request (จาก `/info`): Kotchasan โหลด 27 ไฟล์ (361 KB) เทียบกับ Laravel 338 ไฟล์ (653 KB),
Symfony 198 ไฟล์, CodeIgniter 98 ไฟล์, Slim 79 ไฟล์ — นี่คือเหตุผลหลักของช่องว่าง RPS

การตรวจซ้ำ: รันชุดเดียวกันสองครั้ง ความต่างของ median ส่วนใหญ่ 1–8%
(สูงสุด ~12% ที่ queries ซึ่งผูกกับ MySQL หนักสุด) และ**อันดับของเฟรมเวิร์กเหมือนกันทุกคู่ในทุกสถานการณ์**

## วิธีใช้ (ทำซ้ำบนเครื่องอื่น)

Prerequisites: Docker + Docker Compose v2, Node.js ≥ 18, PHP CLI ≥ 8.1

```sh
make setup     # ติดตั้ง autocannon (เวอร์ชัน pin)
make build     # build images ทั้งหมด (ครั้งแรกนานหน่อย)
make bench     # เปิด stack + verify + รัน benchmark เต็มรูปแบบ (~30 นาที)
make report    # สร้างรายงานจากผลล่าสุด
```

ตัวอย่างปรับพารามิเตอร์:

```sh
RUNS=3 DURATION=5 CONNECTIONS=64 node bench/run.mjs --apps kotchasan,slim --scenarios plaintext,db
```

ตรวจสอบความสามารถในการทำซ้ำ — รันใหม่แล้วเทียบสองรัน:

```sh
make bench
A=results/raw/<รันแรก> B=results/raw/<รันหลัง> make compare
```

เกณฑ์: ความต่าง median ระหว่างสองรันไม่ควรเกิน ~10% (เกิน = เครื่องมีโหลดพื้นหลังรบกวน)

## หน้าเว็บรายงาน & GitHub Pages

`index.html` เป็นเว็บรายงานแบบ **dynamic** — ไม่มีข้อมูลฝังในหน้าเว็บเลย
แต่ fetch ไฟล์ดิบจริงตอนเปิด (`results/runs.json` → `results/raw/<run>/summary.json`
+ `meta.json` + `info.json`) ดังนั้นทุกครั้งที่รัน `make bench` แล้ว commit ผลลง repo
หน้าเว็บจะแสดงผลรันล่าสุดเองโดยไม่ต้องสร้างอะไรซ้ำ และเลือกเทียบ 2 รันได้จาก dropdown

เปิดดูในเครื่อง (ต้องผ่าน HTTP เพราะเบราว์เซอร์บล็อก fetch บน file://):

```sh
make site     # → http://127.0.0.1:8898
```

### เผยแพร่บน GitHub Pages

repo นี้มี workflow สำเร็จรูป (`.github/workflows/pages.yml`) ซึ่งจะประกอบเว็บ
จาก `index.html` + `results/` + `README.md` แล้ว deploy ขึ้น GitHub Pages อัตโนมัติทุกครั้งที่ push ไป `main`

ขั้นตอน (ทำครั้งเดียวหลัง push repo ขึ้น GitHub):

1. สร้าง repository บน GitHub แล้ว push:
   ```sh
   git remote add origin git@github.com:<user>/kotchasan-benchmark.git
   git push -u origin main
   ```
2. บน GitHub: **Settings → Pages → Source: GitHub Actions**
3. รอ workflow "Deploy report to GitHub Pages" เสร็จ แล้วเปิด
   `https://<user>.github.io/kotchasan-benchmark/`

หลังจากนั้นทุกครั้งที่ `make bench` แล้ว commit + push ผลใหม่ หน้าเว็บจะอัปเดตเองภายใน 1–2 นาที
(ถ้า commit ผลใหม่แล้ว dropdown ยังไม่เห็นรันใหม่ — รัน `make index` เพื่อ regenerate `results/runs.json`
ปกติ `run.mjs` จะทำให้อัตโนมัติทุกครั้งที่จบ benchmark)

## ข้อควรระวังในการตีความผล

- นี่คือ **micro-benchmark วัด overhead ของเฟรมเวิร์ก** ไม่ใช่ "แอปจริงจบในเฟรมเวิร์คไหนเร็วกว่า"
  แอปจริงมี I/O อื่น (cache, queue, external API) ที่ทำให้สัดส่วนต่างลดลง
- ไม่ได้วัด ORM/Query Builder ของแต่ละเฟรมเวิร์ก (ใช้ raw PDO เท่ากันหมดโดยตั้งใจ)
- ไม่มีเฟรมเวิร์กไหนเริ่ม session ใน endpoint เหล่านี้ — route ของ Laravel ถูกสมัครนอก
  `web` middleware group (`routes/bench.php` + `then:` ใน bootstrap) เพราะ client ทดสอบไม่ส่ง
  cookie กลับ ถ้าปล่อย session เปิดจะกลายเป็นการวัด "การสร้าง session file ใหม่ทุก request"
  ซึ่งไม่มีเฟรมเวิร์กอื่นในชุดทดสอบทำงานลักษณะนี้ (ผลกระทบ: 777 rps → 2,888 rps)
- ตัวเลข RPS **เปรียบเทียบได้เฉพาะภายในเครื่องเดียวกัน/รันเดียวกัน** — อย่าเทียบข้ามเครื่อง
  ให้ดูอัตราส่วน % เทียบ plain-php ประกอบ
- เฟรมเวิร์กที่ช้ากว่าอาจแลกมาด้วย feature ที่ครบกว่า (validation, ORM, ecosystem) —
  benchmark บอกต้นทุนต่อ request ไม่ได้บอกว่าเฟรมเวิร์กไหน "ดีกว่า"

## โครงสร้างโปรเจ็ค

```
├── index.html                # เว็บรายงาน dynamic (อ่าน results/ ตอนรัน — ใช้กับ GitHub Pages)
├── .github/workflows/pages.yml  # deploy หน้ารายงานขึ้น GitHub Pages อัตโนมัติ
├── docker-compose.yml        # stack ทั้งหมด (nginx + 6 apps + mysql)
├── infra/
│   ├── db/init/              # schema + world dataset (seed คงที่ 10,000 แถว)
│   ├── nginx/nginx.conf      # 6 server blocks แบบเดียวกัน
│   └── php/                  # Dockerfile (ARG APP) + php.ini + php-fpm.conf + bench_shared.php
├── apps/
│   ├── plain-php/            # baseline ไม่มีเฟรมเวิร์ก
│   ├── kotchasan/            # Kotchasan app + สำเนาเฟรมเวิร์กที่พินด้วย commit
│   ├── slim/ codeigniter/ symfony/ laravel/
├── bench/
│   ├── package.json          # autocannon (เวอร์ชัน pin)
│   ├── lib.mjs               # นิยามแอป/สถานการณ์ + contract ตรวจผล
│   ├── verify.mjs            # ตรวจความถูกต้องทุก endpoint ก่อนวัด
│   ├── run.mjs               # orchestrator (warmup + interleave + fingerprint + runs.json)
│   ├── runs-index.mjs        # สร้าง results/runs.json สำหรับหน้าเว็บ
│   ├── report.php            # รายงาน markdown/CSV + เทียบสองรัน
│   └── report-html.php       # สร้าง snapshot HTML แบบฝังข้อมูล (results/REPORT.html)
└── results/
    ├── runs.json             # index ของรันทั้งหมด (หน้าเว็บใช้อ่านรายการรัน)
    ├── REPORT.md / REPORT.html  # snapshot รายงานฉบับเผยแพร่
    └── raw/<ts>/             # ผลดิบ + meta.json + info.json + report ต่อรัน
```

## Kotchasan ในโปรเจ็คนี้มาจากไหน?

Kotchasan ไม่ได้เผยแพร่บน Packagist จึงไม่มีเลขเวอร์ชันกลางให้ pin แบบแพ็กเกจอื่น
โปรเจ็คนี้จึง **vendoring สำเนาโค้ดจริง** ไว้ที่ `apps/kotchasan/Kotchasan/`
และบันทึกที่มา (repo + git commit + วันที่) ไว้ใน `apps/kotchasan/Kotchasan/SOURCE.md`
— วิธีนี้ทำให้ผล benchmark ทำซ้ำได้แม้ repo ต้นทางจะเปลี่ยนไปในอนาคต
