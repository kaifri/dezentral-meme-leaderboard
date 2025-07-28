import requests, json
from datetime import datetime, timezone
import logging
import os
import socket
from dotenv import load_dotenv

# Load environment variables from .env file
load_dotenv()

CONFIG = "config/wallets.json"
OUTPUT = "data/leaderboard.json"
START_SOL_PATH = "data/start_sol_balances.json"
START_DATE = datetime(2025, 7, 27, 22, 0, 1, tzinfo=timezone.utc)

# Get API key from environment variable
HELIUS_API_KEY = os.getenv("HELIUS_API_KEY")
if not HELIUS_API_KEY:
    raise ValueError("HELIUS_API_KEY not found in environment variables. Please check your .env file.")

# Enable debug logging to see API responses
logging.basicConfig(level=logging.CRITICAL)

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
    # Updated Helius API endpoint and method
    url = f"https://api.helius.xyz/v0/addresses/{wallet}/balances?api-key={HELIUS_API_KEY}"
    try:
        response = requests.get(url)
        logging.info(f"Helius balances response status for wallet {wallet}: {response.status_code}")
        logging.debug(f"Helius balances response text for wallet {wallet}: {response.text}")
        res = response.json()
        
        tokens = res.get("tokens", [])
        if not isinstance(tokens, list):
            logging.warning(f"Token balances response for wallet {wallet} is not a list: {tokens}")
            return {}
            
        received = {}
        for token in tokens:
            try:
                mint = token.get("mint")
                amount = float(token.get("amount", 0)) / (10 ** int(token.get("decimals", 0)))
                if amount > 0:
                    received[mint] = received.get(mint, 0) + amount
            except Exception as e:
                logging.warning(f"Error processing token for wallet {wallet}: {e}")
                continue
                
        logging.info(f"Wallet {wallet} tokens: {received}")
        return received
        
    except Exception as e:
        logging.warning(f"Failed to fetch token balances for wallet {wallet}: {e}")
        try:
            logging.warning(f"Helius raw response for wallet {wallet}: {response.text}")
        except Exception:
            pass
        return {}

def get_token_price_usd_dexscreener(mint):
    # Try searching by token address first
    url = f"https://api.dexscreener.com/latest/dex/tokens/{mint}"
    try:
        res = requests.get(url).json()
        logging.debug(f"Dexscreener token response for {mint}: {json.dumps(res, indent=2)}")
        pairs = res.get("pairs", [])
        if pairs:
            # Sort by liquidity to get most reliable price
            pairs.sort(key=lambda x: float(x.get("liquidity", {}).get("usd", 0) or 0), reverse=True)
            for pair in pairs:
                try:
                    base_token = pair.get("baseToken", {}).get("address")
                    quote_token = pair.get("quoteToken", {}).get("address")
                    price = None
                    
                    if base_token == mint:
                        price = pair.get("priceUsd")
                    elif quote_token == mint:
                        base_price = pair.get("priceUsd")
                        if base_price and float(base_price) > 0:
                            price = str(1 / float(base_price))
                    
                    if price and float(price) > 0:
                        liquidity = pair.get("liquidity", {}).get("usd", 0)
                        logging.info(f"Found price for {mint} on {pair.get('dexId')}: ${price} (liquidity: ${liquidity})")
                        return float(price)
                except Exception as e:
                    logging.debug(f"Error processing pair for {mint}: {e}")
                    continue
    except Exception as e:
        logging.warning(f"Failed to fetch Dexscreener price by token address: {e}")

    # Try searching by pair address as fallback
    url = f"https://api.dexscreener.com/latest/dex/search/?q={mint}"
    try:
        res = requests.get(url).json()
        logging.debug(f"Dexscreener search response for {mint}: {json.dumps(res, indent=2)}")
        pairs = res.get("pairs", [])
        if pairs:
            pairs.sort(key=lambda x: float(x.get("liquidity", {}).get("usd", 0) or 0), reverse=True)
            for pair in pairs:
                try:
                    base_token = pair.get("baseToken", {}).get("address")
                    quote_token = pair.get("quoteToken", {}).get("address")
                    price = None
                    
                    if base_token == mint:
                        price = pair.get("priceUsd")
                    elif quote_token == mint:
                        base_price = pair.get("priceUsd")
                        if base_price and float(base_price) > 0:
                            price = str(1 / float(base_price))
                            
                    if price and float(price) > 0:
                        liquidity = pair.get("liquidity", {}).get("usd", 0)
                        logging.info(f"Found price via search for {mint} on {pair.get('dexId')}: ${price} (liquidity: ${liquidity})")
                        return float(price)
                except Exception as e:
                    logging.debug(f"Error processing search pair for {mint}: {e}")
                    continue
    except Exception as e:
        logging.warning(f"Failed to fetch Dexscreener price by search: {e}")
    
    logging.warning(f"No valid price found for {mint} on Dexscreener")
    return 0

def get_sol_price_usd():
    # Try Jupiter first
    url = "https://api.jup.ag/v4/price?ids=So11111111111111111111111111111111111111112&vsToken=USDC"
    try:
        res = requests.get(url, timeout=5).json()
        price = float(res['data']['So11111111111111111111111111111111111111112']['price'])
        if price > 0:
            logging.info(f"Got SOL price from Jupiter: ${price}")
            return price
    except Exception as e:
        logging.warning(f"Jupiter SOL price fetch failed: {e}")
    
    # Fallback to Dexscreener for SOL price
    try:
        sol_price = get_token_price_usd_dexscreener("So11111111111111111111111111111111111111112")
        if sol_price > 0:
            logging.info(f"Got SOL price from Dexscreener: ${sol_price}")
            return sol_price
    except Exception as e:
        logging.warning(f"Dexscreener SOL price fetch failed: {e}")
    
    logging.warning("Could not fetch SOL price from any source")
    return 0

def get_token_prices_in_sol(mints, sol_price_usd):
    prices = {}
    logging.info(f"SOL price USD: {sol_price_usd}")
    for mint in mints:
        try:
            # Try Dexscreener first for price discovery
            logging.info(f"Fetching price for token {mint}")
            token_price_usd = get_token_price_usd_dexscreener(mint)
            logging.info(f"Dexscreener returned price: {token_price_usd} for {mint}")
            
            if token_price_usd > 0 and sol_price_usd > 0:
                prices[mint] = token_price_usd / sol_price_usd
                logging.info(f"Fetched price from Dexscreener for {mint}: {prices[mint]} SOL (${token_price_usd} USD)")
                continue
            elif token_price_usd > 0 and sol_price_usd == 0:
                logging.warning(f"Token price found but SOL price is 0, cannot convert to SOL")
            
            # If Dexscreener fails, try Jupiter with corrected API domain
            url = f"https://api.jup.ag/v4/price?ids={mint}&vsToken=So11111111111111111111111111111111111111112"
            for attempt in range(3):
                try:
                    res = requests.get(url, timeout=5).json()
                    if "data" in res and mint in res["data"]:
                        price = float(res['data'][mint]['price'])
                        logging.info(f"Fetched price from Jupiter for {mint}: {price}")
                        if price > 0:
                            prices[mint] = price
                            break
                except (requests.exceptions.ConnectionError, 
                       requests.exceptions.Timeout,
                       requests.exceptions.RequestException,
                       socket.gaierror) as e:
                    if attempt == 2:
                        logging.warning(f"Jupiter price fetch failed after retries for {mint}: {e}")
                    continue

            # If both fail, set price to 0
            if mint not in prices:
                prices[mint] = 0
                logging.warning(f"Could not fetch price for {mint} from either source, setting to 0")

        except Exception as e:
            logging.warning(f"Price fetch completely failed for {mint}: {e}")
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

sol_price_usd = get_sol_price_usd()

for entry in wallets:
    wallet = entry["wallet"]
    username = entry.get("username", wallet[:6])

    if wallet not in start_sols:
        print(f"[WARN] Missing start balance for wallet {wallet}. Skipping.")
        continue

    sol = get_sol_balance(wallet)
    tokens = get_token_transfers(wallet)
    logging.info(f"get_token_transfers({wallet}) returned: {tokens}")

    token_value = 0
    if tokens:
        logging.info(f"Wallet {wallet} tokens: " + ", ".join([f"{mint}: {amount}" for mint, amount in tokens.items()]))
        prices = get_token_prices_in_sol(tokens.keys(), sol_price_usd)
        for mint, amount in tokens.items():
            logging.info(f"Wallet {wallet}: {amount} of token {mint} at price {prices.get(mint, 0)}")
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
    json.dump({"updated": datetime.now(timezone.utc).isoformat() + "Z", "data": leaderboard}, f, indent=2)
