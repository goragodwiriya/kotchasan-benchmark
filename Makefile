COMPOSE := docker compose

.PHONY: help setup build up down verify bench smoke report site index compare clean nuke

help:
	@echo "Kotchasan vs PHP frameworks — benchmark suite"
	@echo "  make setup    ติดตั้งเครื่องมือวัด (autocannon ผ่าน npm)"
	@echo "  make build    สร้าง Docker images ของทุกแอป"
	@echo "  make up       เปิด stack (nginx + 6 apps + mysql)"
	@echo "  make down     ปิด stack"
	@echo "  make verify   ตรวจความถูกต้องของทุก endpoint ก่อนวัดผล"
	@echo "  make bench    รัน benchmark เต็มรูปแบบ (verify ก่อนอัตโนมัติ)"
	@echo "  make smoke    ทดสอบเร็ว 1 app × 1 scenario × 5 วินาที"
	@echo "  make report   สร้างรายงานจากผลล่าสุด"
	@echo "  make site     เปิดเว็บรายงาน (index.html) แบบ local — http://127.0.0.1:8898"
	@echo "  make index    regenerate results/runs.json สำหรับหน้าเว็บรายงาน"
	@echo "  make compare A=... B=... เทียบสองรัน (reproducibility)"
	@echo "  make nuke     ลบ stack + volume ทั้งหมด (DB จะ seed ใหม่เมื่อ up ครั้งถัดไป)"

setup:
	cd bench && npm install --no-fund --no-audit

build:
	$(COMPOSE) build

up:
	$(COMPOSE) up -d --wait
	@# nginx resolves upstream container IPs at startup; restart it so it
	@# re-resolves after any app container was recreated with a new IP.
	$(COMPOSE) restart nginx

down:
	$(COMPOSE) down

verify:
	$(COMPOSE) up -d --wait
	$(COMPOSE) restart nginx
	node bench/verify.mjs

bench:
	$(COMPOSE) up -d --wait
	$(COMPOSE) restart nginx
	node bench/verify.mjs
	node bench/run.mjs

smoke:
	$(COMPOSE) up -d --wait
	$(COMPOSE) restart nginx
	node bench/verify.mjs
	RUNS=1 DURATION=5 WARMUP=3 CONNECTIONS=32 node bench/run.mjs --apps kotchasan,plain-php --scenarios plaintext,json

report:
	@latest=$$(ls -t results/raw | head -1); php bench/report.php results/raw/$$latest

site:
	php -S 127.0.0.1:8898 >/dev/null 2>&1 & echo "เปิด http://127.0.0.1:8898 (Ctrl+C หรือ pkill -f 8898 เพื่อปิด)"

index:
	node bench/runs-index.mjs

compare:
	php bench/report.php $(A) $(B)

clean:
	$(COMPOSE) down --remove-orphans

nuke:
	$(COMPOSE) down -v --remove-orphans
