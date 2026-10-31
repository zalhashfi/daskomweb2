#!/bin/sh
# Test pre-push hook terhadap skenario nyata (repo sementara).
# Pemakaian: sh test-pre-push.sh

HOOK="C:/Users/zalhashfi/.git-hooks/pre-push"
REQUIRED="211019493+zalhashfi@users.noreply.github.com"
PASS=0; FAIL=0

setup_repo() {
  T=$(mktemp -d)
  cd "$T" || exit 1
  git init -q --bare remote.git
  git init -q work
  cd work || exit 1
  git config user.name zalhashfi
  git config user.email "$REQUIRED"
  git remote add origin ../remote.git
  cp "$HOOK" .git/hooks/pre-push
  git config core.hooksPath .git/hooks   # isolasi: hanya pre-push yang diuji
  chmod +x .git/hooks/pre-push
}

expect() { # $1 = expected(0 ok / 1 blocked), $2 = label
  out=$(git push -q origin HEAD:refs/heads/testbranch 2>&1); rc=$?
  if [ "$1" = "0" ] && [ "$rc" -eq 0 ]; then PASS=$((PASS+1)); printf '  PASS  %s\n' "$2"
  elif [ "$1" = "1" ] && [ "$rc" -ne 0 ]; then PASS=$((PASS+1)); printf '  PASS  %s\n' "$2"
  else FAIL=$((FAIL+1)); printf '  FAIL  %s (rc=%s)\n%s\n' "$2" "$rc" "$out"; fi
  git push -q origin --delete testbranch 2>/dev/null
}

echo "1) .env.example boleh di-push"
setup_repo; echo "APP_KEY=" > .env.example; git add -f -A; git commit -qm "a"; expect 0 ".env.example lolos"; rm -rf "$T"

echo "2) .env.testing.example boleh di-push"
setup_repo; echo "DB_PASSWORD=" > .env.testing.example; git add -f -A; git commit -qm "a"; expect 0 ".env.testing.example lolos"; rm -rf "$T"

echo "3) .env asli DIBLOKIR"
setup_repo; echo "SECRET=1" > .env; git add -f -A; git commit -qm "a"; expect 1 ".env diblokir"; rm -rf "$T"

echo "4) .env.production DIBLOKIR"
setup_repo; echo "SECRET=1" > .env.production; git add -f -A; git commit -qm "a"; expect 1 ".env.production diblokir"; rm -rf "$T"

echo "5) server.key DIBLOKIR"
setup_repo; echo "k" > server.key; git add -A; git commit -qm "a"; expect 1 "server.key diblokir"; rm -rf "$T"

echo "6) .planning/ DIBLOKIR"
setup_repo; mkdir .planning; echo "x" > .planning/STATE.md; git add -A; git commit -qm "a"; expect 1 ".planning diblokir"; rm -rf "$T"

echo "7) email author salah DIBLOKIR"
setup_repo; echo a > a.txt; git add -A; git -c user.email=wrong@example.com commit -qm "a"; expect 1 "email salah diblokir"; rm -rf "$T"

echo "8) trailer Co-authored-by DIBLOKIR"
setup_repo; echo a > a.txt; git add -A; git commit -qm "a" -m "Co-authored-by: Bot <bot@example.com>"; expect 1 "trailer diblokir"; rm -rf "$T"

echo "9) branch baru dengan riwayat merge lama: hasil sama saat diulang"
setup_repo
echo a > a.txt; git add -A; git commit -qm "base"; DEFAULT=$(git symbolic-ref --short HEAD)
git checkout -q -b old; echo b > b.txt; git add -A; git commit -qm "old work"
git checkout -q "$DEFAULT"
git merge -q --no-ff old -m "merge old"
expect 0 "push pertama lolos"
expect 0 "push ulang tetap lolos (deterministik)"
rm -rf "$T"

echo
echo "LULUS=$PASS GAGAL=$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
