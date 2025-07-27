import requests, json
from datetime import datetime, timezone

CONFIG = "config/wallets.json"
OUTPUT = "data/leaderboard.json"
START_SOL_PATH = "data/start_sol_balances.json"
START_DATE = datetime(2025, 7, 27, 22, 0, 1, tzinfo=timezone.utc)  # entspricht 28.07.2025 00:00:01 MESZ

def get_sol_balance(wallet):
    url = "https://api.mainnet-beta.solana.com"
    headers = {"Content-Type": "application/json"}
    body = {
        "jsonrpc": "2.0",
        "id": 1,
        "method": "getBalance",
        "params": [wallet]
    }
    try:
        res = requests.post(url, headers=headers, json=body).json()
        lamports = res["result"]["value"]
        return lamports / 1_000_000_000
    except:
        return 0

def get_token_transfers(wallet):
    url = f"https://public-api.solscan.io/account/splTransfers?account={wallet}&limit=1000"
    headers = {"accept": "application/json"}
    try:
        res = requests.get(url, headers=headers).json()
    except:
        return {}

    received = {}
    for tx in res:
        try:
            if tx["destination"] != wallet:
                continue
            timestamp = datetime.fromtimestamp(tx["blockTime"], tz=timezone.utc)
            if timestamp < START_DATE:
                continue
            mint = tx["tokenAddress"]
            amount = float(tx["changeAmount"]) / (10 ** int(tx["tokenDecimal"]))
            received[mint] = received.get(mint, 0) + amount
        except:
            continue
    return received

def get_token_prices_in_sol(mints):
    prices = {}
    for mint in mints:
        try:
            url = f"https://price.jup.ag/v4/price?ids={mint}&vsToken=So11111111111111111111111111111111111111112"
            res = requests.get(url).json()
            prices[mint] = float(res['data'][mint]['price'])
        except:
            prices[mint] = 0
    return prices

# Lade Wallets
with open(CONFIG) as f:
    wallets = json.load(f)

# Lade festgelegte Startwerte
try:
    with open(START_SOL_PATH, "r") as f:
        start_sols = json.load(f)
except:
    start_sols = {}

leaderboard = []

for entry in wallets:
    wallet = entry["wallet"]
    username = entry.get("username", wallet[:6])

    if wallet not in start_sols:
        print(f"[WARN] Missing start balance for wallet {wallet}. Skipping.")
        continue

    sol = get_sol_balance(wallet)
    tokens = get_token_transfers(wallet)

    token_value = 0
    if tokens:
        prices = get_token_prices_in_sol(tokens.keys())
        for mint, amount in tokens.items():
            token_value += amount * prices.get(mint, 0)

    total = sol + token_value
    start = start_sols[wallet]
    change_pct = ((total - start) / start * 100) if start > 0 else 0

    leaderboard.append({
        "username": username,
        "wallet": wallet,
        "sol": round(sol, 4),
        "tokens": round(token_value, 4),
        "total": round(total, 4),
        "change_pct": round(change_pct, 2)
    })

leaderboard.sort(key=lambda x: x["total"], reverse=True)

with open(OUTPUT, "w") as f:
    json.dump({"updated": datetime.utcnow().isoformat() + "Z", "data": leaderboard}, f, indent=2)
