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