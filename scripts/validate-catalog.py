#!/usr/bin/env python3
"""게임 카탈로그를 Pelican 의 실제 egg 와 대조 검증한다 (이슈 #16).

카탈로그가 참조하는 egg 이름·변수명이 실제와 어긋나면 개설이 **조용히 깨진다**
(없는 변수는 무시되고 egg 기본값이 쓰인다). egg 는 업스트림에서 갱신되므로
주기적으로, 그리고 egg 임포트/갱신 후에 반드시 돌린다.

사용:
    docker exec pelican-panel php artisan tinker --execute='...' 로 egg 를 덤프한 뒤
    python3 validate.py games.yaml eggs.json
"""
import json
import re
import sys

import yaml


FORGE_PROMOS = "https://files.minecraftforge.net/net/minecraftforge/forge/promotions_slim.json"


def forge_promos() -> dict | None:
    """Forge 의 버전 승격 목록. 못 받아오면 None — 검사를 건너뛴다(네트워크 없는 환경 대비)."""
    try:
        import urllib.request

        # ⚠ User-Agent 를 안 붙이면 403 이다. 기본 python-urllib 를 막는다.
        request = urllib.request.Request(FORGE_PROMOS, headers={"User-Agent": "curl/8"})
        with urllib.request.urlopen(request, timeout=10) as r:
            promos = json.load(r)["promos"]
    except Exception:
        return None

    # egg 스크립트가 지우는 두 개를 똑같이 지운다.
    promos.pop("latest-1.7.10", None)
    promos.pop("1.7.10-latest-1.7.10", None)
    return promos


def load(catalog_path: str, eggs_path: str):
    with open(catalog_path, encoding="utf-8") as f:
        catalog = yaml.safe_load(f)
    with open(eggs_path, encoding="utf-8") as f:
        eggs = json.load(f)
    return catalog, {e["name"]: e for e in eggs}


def rule_max(rules: list[str]) -> int | None:
    """egg 규칙에서 상한을 뽑는다 (max:N / between:A,B)."""
    for r in rules:
        if m := re.fullmatch(r"max:(\d+)", r):
            return int(m.group(1))
        if m := re.fullmatch(r"between:\d+,(\d+)", r):
            return int(m.group(1))
    return None


def main() -> int:
    catalog, eggs = load(sys.argv[1], sys.argv[2])
    errors: list[str] = []
    warnings: list[str] = []
    promos = forge_promos()
    if promos is None:
        warnings.append("Forge promotions 를 받지 못해 버전 검사를 건너뛴다 (네트워크?)")

    for game in catalog["games"]:
        gid = game["id"]
        egg_name = game["egg"]

        egg = eggs.get(egg_name)
        if not egg:
            errors.append(f"[{gid}] egg '{egg_name}' 가 Pelican 에 없다 (임포트 필요)")
            continue

        by_env = {v["env_variable"]: v for v in egg["variables"]}
        editable = {k for k, v in by_env.items() if v["user_editable"]}

        # ask / defaults / derive 가 참조하는 변수가 실제로 있는가
        referenced: list[tuple[str, str]] = []
        referenced += [(a["env"], "ask") for a in game.get("ask") or []]
        referenced += [(k, "defaults") for k in (game.get("defaults") or {})]
        referenced += [
            (d["env"], "ports.derive") for d in (game.get("ports", {}).get("derive") or [])
        ]
        if pv := game.get("player_var"):
            referenced.append((pv, "player_var"))

        for env, where in referenced:
            if env not in by_env:
                errors.append(f"[{gid}] {where}: egg 에 없는 변수 '{env}'")
            elif env not in editable:
                # user_editable=false 여도 플러그인이 값을 넣긴 한다(검증됨).
                # 다만 의도치 않은 경우가 대부분이라 경고한다.
                warnings.append(f"[{gid}] {where}: '{env}' 는 user_editable=false")

        # 사용자에게 물으면서 카탈로그가 상한을 안 건 변수 — egg 규칙이 느슨하면 위험
        for a in game.get("ask") or []:
            var = by_env.get(a["env"])
            if not var:
                continue
            rules = var.get("rules") or []
            has_numeric = any(r in ("integer", "numeric") for r in rules)
            if has_numeric and rule_max(rules) is None and "max" not in a:
                warnings.append(
                    f"[{gid}] ask '{a['env']}' 는 숫자인데 egg 규칙과 카탈로그 모두 상한이 없다"
                )

        # 인원 프리셋이 player_var 의 egg 상한을 넘지 않는가
        if pv := game.get("player_var"):
            if var := by_env.get(pv):
                cap = rule_max(var.get("rules") or [])
                if cap is not None:
                    for s in game["sizes"]:
                        if s.get("players", 0) > cap:
                            errors.append(
                                f"[{gid}] sizes.{s['id']} players={s['players']} 가 "
                                f"egg 의 {pv} 상한 {cap} 을 넘는다"
                            )

        # 시작 명령에 치환되는 시크릿이 catalog.secrets 에 선언돼 있는가.
        # Pelican 콘솔은 시작 명령을 평문 출력하므로 노출 대상을 알고 있어야 한다(#13).
        declared = set(game.get("secrets") or [])
        secret_like = re.compile(r"(PASS|LICENSE|KEY|TOKEN|SECRET)")
        for env in by_env:
            if secret_like.search(env) and env not in declared:
                # egg 에 있지만 시작 명령에 안 쓰이면 노출되지 않으므로 후보에서만 언급
                if env in (a["env"] for a in (game.get("ask") or [])):
                    warnings.append(
                        f"[{gid}] ask '{env}' 는 시크릿으로 보이는데 secrets 에 없다"
                    )

        # 설치 완료 판정 하한. 배정 디스크보다 크면 정상 설치도 실패로 잡힌다.
        floor = game.get("install_min_mb")
        if floor is not None:
            smallest = min(s["disk"] for s in game["sizes"])
            if floor >= smallest:
                errors.append(
                    f"[{gid}] install_min_mb={floor} 가 가장 작은 sizes.disk={smallest} 이상이다 "
                    f"— 정상 설치도 미달로 잡힌다"
                )
        elif game.get("verified"):
            warnings.append(f"[{gid}] 설치를 실측했는데 install_min_mb 가 없다")

        # 🔴 Forge 는 promotions 를 exact 가 아니라 contains() 로 찾는다.
        # 다른 버전의 접두사가 되는 값을 고르면 키가 여러 개 잡혀 조회 결과가 null 이 되고,
        # 설치가 텅 빈 채 installed 로 표시된다(실측: 1.21 · 1.21.1 이 그랬다).
        if "Forge" in egg_name and promos is not None:
            build_type = (game.get("defaults") or {}).get("BUILD_TYPE", "recommended")
            for a in game.get("ask") or []:
                if a["env"] != "MC_VERSION":
                    continue
                for choice in a.get("choices") or []:
                    hits = [k for k in promos if choice in k and build_type in k]
                    if len(hits) != 1:
                        errors.append(
                            f"[{gid}] MC_VERSION '{choice}' + BUILD_TYPE '{build_type}' 가 "
                            f"Forge promotions 에서 {len(hits)}개로 잡힌다(1개여야 한다): {hits[:4]}"
                        )

        for s in game["sizes"]:
            for key in ("memory", "disk", "cpu"):
                if s.get(key, 0) <= 0:
                    errors.append(f"[{gid}] sizes.{s['id']}.{key} 가 0 이하다")

    for w in warnings:
        print(f"  경고  {w}")
    for e in errors:
        print(f"  오류  {e}")
    print(f"\n게임 {len(catalog['games'])}개 · 오류 {len(errors)} · 경고 {len(warnings)}")
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
