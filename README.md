```markdown
# 🏆 Solana Leaderboard

Zeigt den aktuellen SOL + Token-Wert pro Wallet ab dem Startzeitpunkt 28.07.2025. % Change basiert auf dem damaligen SOL-Startwert.

- Automatische Updates alle 15 Minuten per GitHub Action
- Hosted via GitHub Pages

## Installation (lokal)
```bash
pip install requests
python update.py
```

## Deployment
- GitHub Actions: siehe `.github/workflows/update.yml`
- GitHub Pages: index.html + data/* automatisch öffentlich

## Setup

1. Clone the repository
2. Copy `.env.example` to `.env`
3. Fill in your API keys in the `.env` file:
   ```
   HELIUS_API_KEY=your_actual_api_key_here
   ```
4. Install dependencies:
   ```bash
   pip install requests python-dotenv
   ```
5. Run the script:
   ```bash
   python update.py
   ```

## Environment Variables

- `HELIUS_API_KEY`: Your Helius API key for fetching token balances

## Git Workflow

The `.env` file is ignored by Git, so your API keys stay private. When someone clones the repo, they need to:
1. Copy `.env.example` to `.env`
2. Add their own API keys
```