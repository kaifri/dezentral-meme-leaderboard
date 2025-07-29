<?php
// Load secure configuration server-side
define('CONFIG_ACCESS', true);
$config = require_once __DIR__ . '/config/config.php';

// Extract only what the frontend needs (no sensitive data)
$frontend_config = [
    'api_base_url' => $config['api']['base_url'],
    'api_token' => $config['api']['token'], // This will be embedded in JS, but not in a separate file
    'update_interval_seconds' => $config['app']['update_interval_seconds'],
    'timezone' => $config['app']['timezone'],
    'challenge_end_date' => $config['app']['challenge_end_date']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>🏆 BfdA Solana Shitcoin Contest Leaderboard 🏆</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    @keyframes glow {
      0%, 100% { box-shadow: 0 0 20px rgba(255, 215, 0, 0.6); }
      50% { box-shadow: 0 0 30px rgba(255, 215, 0, 0.9); }
    }
    
    @keyframes pulse-slow {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.8; }
    }
    
    .winner-glow {
      animation: glow 2s ease-in-out infinite;
    }
    
    .pulse-slow {
      animation: pulse-slow 3s ease-in-out infinite;
    }
    
    .gradient-text {
      background: linear-gradient(45deg, #ffd700, #ffed4e, #ffd700);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }
  </style>
</head>
<body class="bg-gray-900 text-white min-h-screen flex flex-col items-center py-4 sm:py-10 px-2 sm:px-4">
  <h1 class="text-2xl sm:text-5xl font-bold mb-4 gradient-text pulse-slow text-center">🔥 BfdA Solana Shitcoin Contest 🔥</h1>
  
  <!-- Challenge Status Banner -->
  <div id="challenge-status" class="mb-4 px-3 sm:px-6 py-2 sm:py-3 rounded-lg text-center hidden w-full max-w-md">
    <div id="challenge-active" class="bg-gradient-to-r from-green-800 to-emerald-700 text-green-200 rounded-lg px-2 sm:px-4 py-2 hidden text-xs sm:text-sm">
      🟢 Challenge läuft bis <span id="end-date" class="font-bold block sm:inline"></span>
    </div>
    <div id="challenge-ended" class="bg-gradient-to-r from-red-800 to-red-700 text-red-200 rounded-lg px-2 sm:px-4 py-2 hidden text-xs sm:text-sm">
      🔴 Challenge beendet! Finale Wertung nach SOL-Balance
    </div>
  </div>

  <!-- Contest Rules -->
  <div class="mb-6 w-full max-w-4xl">
    <div class="bg-gradient-to-r from-blue-900/30 via-purple-900/30 to-blue-900/30 border border-blue-700/50 rounded-xl p-4 sm:p-6">
      <h2 class="text-lg sm:text-xl font-bold text-blue-300 mb-3 text-center">📋 Contest Regeln</h2>
      <div class="grid md:grid-cols-2 gap-4 text-sm">
        <div>
          <h3 class="text-blue-400 font-semibold mb-2">⏰ Zeitraum</h3>
          <p class="text-gray-300">Start: Montag 28.07. 0:00<br>Ende: Sonntag 03.08. 24:00</p>
          
          <h3 class="text-blue-400 font-semibold mb-2 mt-4">💰 Teilnahme</h3>
          <p class="text-gray-300">0,5 SOL Startkapital<br>0,5 SOL Winner Pot Beitrag</p>
          
          <h3 class="text-blue-400 font-semibold mb-2 mt-4">🏆 Gewinner</h3>
          <p class="text-gray-300">Höchste <strong>SOL-Balance</strong> zum Endzeitpunkt gewinnt</p>
        </div>
        <div>
          <h3 class="text-blue-400 font-semibold mb-2">✅ Erlaubt</h3>
          <ul class="text-gray-300 space-y-1">
            <li>• Gleiche Coins wie andere kaufen</li>
            <li>• Reload bei &lt;0.05 SOL möglich</li>
            <li>• Teilnahme bis 24h nach Start</li>
          </ul>
          
          <h3 class="text-red-400 font-semibold mb-2 mt-4">❌ Verboten</h3>
          <ul class="text-gray-300 space-y-1">
            <li>• Copy Trading der Wallets</li>
            <li>• Manipulation</li>
          </ul>
        </div>
      </div>
    </div>
  </div>

  <!-- Winner Pot Display -->
  <div id="winner-pot" class="mb-4 sm:mb-6 bg-gradient-to-r from-yellow-800 via-yellow-600 to-yellow-800 px-4 sm:px-8 py-3 sm:py-4 rounded-xl text-center shadow-2xl hidden w-full max-w-sm">
    <div class="text-yellow-200 text-xs sm:text-sm">🏆 Gewinner-Pot</div>
    <div class="text-yellow-100 text-lg sm:text-2xl font-bold"><span id="pot-balance">0</span> SOL</div>
    <div class="text-yellow-200 text-xs">
      Wallet: 
      <a href="#" id="pot-wallet-link" target="_blank" class="font-mono hover:text-yellow-100 underline transition-colors">
        <span id="pot-wallet"></span>
      </a>
    </div>
  </div>

  <p class="mb-4 sm:mb-6 text-xs sm:text-sm text-gray-400">🕒 Update: <span id="last-updated">...</span></p>
  
  <!-- Ranking Mode Info -->
  <div id="ranking-info" class="mb-4 px-4 py-2 rounded-lg text-center text-xs sm:text-sm hidden">
    <div id="live-ranking" class="bg-yellow-900/30 border border-yellow-600 text-yellow-200 rounded hidden">
      📊 Live Ranking: SOL + Token Werte
    </div>
    <div id="final-ranking" class="bg-red-900/30 border border-red-600 text-red-200 rounded hidden">
      🏁 Finale Wertung: Nur SOL-Balance zählt!
    </div>
  </div>
  
  <!-- Desktop Table View -->
  <div class="hidden md:block w-full max-w-6xl overflow-x-auto">
    <table class="min-w-full bg-gray-800 shadow-2xl rounded-xl overflow-hidden">
      <thead class="bg-gradient-to-r from-yellow-600 via-yellow-500 to-yellow-600 text-left">
        <tr>
          <th class="px-6 py-4 font-bold text-yellow-900">#</th>
          <th class="px-6 py-4 font-bold text-yellow-900">Username</th>
          <th class="px-6 py-4 font-bold text-yellow-900">Wallet</th>
          <th class="px-6 py-4 text-right font-bold text-yellow-900">SOL</th>
          <th class="px-6 py-4 text-right font-bold text-yellow-900">Tokens</th>
          <th class="px-6 py-4 text-right font-bold text-yellow-900" id="total-header">Total (SOL)</th>
          <th class="px-6 py-4 text-right font-bold text-yellow-900">% Change</th>
        </tr>
      </thead>
      <tbody id="leaderboard-body"></tbody>
    </table>
  </div>

  <!-- Mobile Card View -->
  <div class="md:hidden w-full max-w-sm space-y-3" id="mobile-leaderboard">
    <!-- Cards will be generated here -->
  </div>

  <!-- Winner Announcement -->
  <div id="winner-announcement" class="mt-6 sm:mt-8 bg-gradient-to-r from-yellow-700 via-orange-600 to-yellow-700 px-4 sm:px-8 py-4 sm:py-6 rounded-xl text-center shadow-2xl winner-glow hidden w-full max-w-md">
    <div class="text-xl sm:text-3xl mb-2 sm:mb-3">🎉 Herzlichen Glückwunsch! 🎉</div>
    <div class="text-lg sm:text-2xl text-yellow-200 mb-2 sm:mb-3">Gewinner: <span id="winner-name" class="font-bold gradient-text"></span></div>
    <div class="text-base sm:text-xl">Finale SOL-Balance: <span id="winner-total" class="font-bold text-yellow-100"></span> SOL</div>
    <div class="text-sm sm:text-lg text-yellow-200 mt-2">Gewinn: <span id="winner-change" class="font-bold"></span>%</div>
  </div>

  <script>
    // Configuration injected server-side (no API token needed for GET requests)
    const CONFIG = {
        api_base_url: '<?php echo $config['api']['base_url']; ?>',
        update_interval_seconds: <?php echo $config['app']['update_interval_seconds']; ?>,
        timezone: '<?php echo $config['app']['timezone']; ?>',
        challenge_end_date: '<?php echo $config['app']['challenge_end_date']; ?>'
    };
    
    console.log('Configuration loaded from server:', CONFIG);

    function getTimeAgo(isoString) {
      const now = new Date();
      const updated = new Date(isoString);
      const diffMs = now - updated;
      const diffMins = Math.floor(diffMs / 60000);
      
      if (diffMins < 1) return "gerade eben";
      if (diffMins === 1) return "vor 1 Minute";
      if (diffMins < 60) return `vor ${diffMins} Minuten`;
      
      const diffHours = Math.floor(diffMins / 60);
      if (diffHours === 1) return "vor 1 Stunde";
      if (diffHours < 24) return `vor ${diffHours} Stunden`;
      
      const diffDays = Math.floor(diffHours / 24);
      return `vor ${diffDays} Tag${diffDays === 1 ? '' : 'en'}`;
    }

    function getRankEmoji(rank) {
      switch(rank) {
        case 1: return '🥇';
        case 2: return '🥈';
        case 3: return '🥉';
        case 4: return '🏅';
        case 5: return '🏅';
        default: return rank;
      }
    }

    function createMobileCard(entry, rank, challengeEnded, finalRanking) {
      const changeColor = entry.change_pct > 0 ? 'text-green-400' : (entry.change_pct < 0 ? 'text-red-400' : 'text-gray-300');
      const changeText = entry.change_pct > 0 ? '▲' : (entry.change_pct < 0 ? '▼' : '–');
      
      // Use SOL balance for final ranking
      const displayTotal = finalRanking ? entry.sol : entry.total;
      const totalLabel = finalRanking ? 'SOL' : 'Total SOL';
      
      let cardClass = "bg-gray-800 rounded-lg p-4 shadow-lg";
      let rankDisplay = getRankEmoji(rank);
      
      if (rank === 1) {
        cardClass = "bg-gradient-to-r from-yellow-900 to-yellow-800 border-l-4 border-yellow-400 rounded-lg p-4 shadow-lg";
        if (challengeEnded) cardClass += " winner-glow";
      } else if (rank === 2) {
        cardClass = "bg-gradient-to-r from-gray-800 to-gray-700 border-l-4 border-gray-400 rounded-lg p-4 shadow-lg";
      } else if (rank === 3) {
        cardClass = "bg-gradient-to-r from-orange-900 to-orange-800 border-l-4 border-orange-400 rounded-lg p-4 shadow-lg";
      }

      return `
        <div class="${cardClass}">
          <div class="flex items-center justify-between mb-3">
            <div class="flex items-center space-x-3">
              <span class="text-2xl font-bold">${rankDisplay}</span>
              <div>
                <div class="font-semibold ${rank === 1 ? 'text-yellow-200 text-lg' : rank <= 3 ? 'text-white' : 'text-gray-200'}">${entry.username}</div>
                <a href="https://solscan.io/account/${entry.wallet}" target="_blank" class="text-xs text-gray-400 hover:text-blue-400 transition-colors">
                  ${entry.wallet.slice(0, 8)}...${entry.wallet.slice(-6)}
                </a>
              </div>
            </div>
            <div class="text-right">
              <div class="font-bold ${rank === 1 ? 'text-yellow-200 text-lg' : rank <= 3 ? 'text-yellow-400' : 'text-yellow-400'}">${displayTotal.toFixed(4)} ${finalRanking ? 'SOL' : 'SOL'}</div>
              <div class="text-xs ${changeColor} font-mono">${changeText} ${entry.change_pct.toFixed(2)}%</div>
            </div>
          </div>
          <div class="grid grid-cols-2 gap-4 text-sm">
            <div>
              <span class="text-gray-400">SOL:</span>
              <span class="font-mono ml-1">${entry.sol.toFixed(4)}</span>
            </div>
            <div>
              <span class="text-gray-400">Tokens:</span>
              <span class="font-mono ml-1 text-green-400">${entry.tokens.toFixed(4)}</span>
            </div>
          </div>
          ${finalRanking ? '<div class="mt-2 text-xs text-red-300 text-center">🏁 Finale Wertung: Nur SOL zählt!</div>' : ''}
        </div>
      `;
    }

    function updateLeaderboard() {
      fetch(`${CONFIG.api_base_url}/leaderboard.php`, {
        method: 'GET',
        headers: {
          'Content-Type': 'application/json'
        }
      })
        .then(res => {
          if (!res.ok) {
            throw new Error(`HTTP error! status: ${res.status}`);
          }
          return res.json();
        })
        .then(json => {
          const tbody = document.getElementById("leaderboard-body");
          const mobileContainer = document.getElementById("mobile-leaderboard");
          const updated = document.getElementById("last-updated");
          const challengeStatus = document.getElementById("challenge-status");
          const challengeActive = document.getElementById("challenge-active");
          const challengeEnded = document.getElementById("challenge-ended");
          const endDate = document.getElementById("end-date");
          const rankingInfo = document.getElementById("ranking-info");
          const liveRanking = document.getElementById("live-ranking");
          const finalRanking = document.getElementById("final-ranking");
          const totalHeader = document.getElementById("total-header");
          
        
          const winnerPot = document.getElementById("winner-pot");
          const potBalance = document.getElementById("pot-balance");
          const potWallet = document.getElementById("pot-wallet");
          const potWalletLink = document.getElementById("pot-wallet-link");
          const winnerAnnouncement = document.getElementById("winner-announcement");
          const winnerName = document.getElementById("winner-name");
          const winnerTotal = document.getElementById("winner-total");
          const winnerChange = document.getElementById("winner-change");
          
          // Update "updated x minutes ago"
          updated.textContent = getTimeAgo(json.updated);
          updated.dataset.lastUpdate = json.updated;
          tbody.innerHTML = "";
          mobileContainer.innerHTML = "";

          // Update challenge status
          challengeStatus.classList.remove("hidden");
          if (json.challenge_ended) {
            challengeActive.classList.add("hidden");
            challengeEnded.classList.remove("hidden");
          } else {
            challengeActive.classList.remove("hidden");
            challengeEnded.classList.add("hidden");
            const endDateUTC = new Date(json.challenge_end_date);
            endDate.textContent = endDateUTC.toLocaleString('de-DE', {
              timeZone: CONFIG.timezone,
              year: 'numeric',
              month: '2-digit',
              day: '2-digit',
              hour: '2-digit',
              minute: '2-digit',
              second: '2-digit'
            });
          }

          // Update winner pot with clickable wallet
          if (json.winner_pot) {
            winnerPot.classList.remove("hidden");
            potBalance.textContent = json.winner_pot.balance;
            potWallet.textContent = `${json.winner_pot.wallet.slice(0, 4)}...${json.winner_pot.wallet.slice(-4)}`;
            potWalletLink.href = `https://solscan.io/account/${json.winner_pot.wallet}`;
          }

          // Show winner announcement if challenge ended
          if (json.challenge_ended && json.data.length > 0) {
            const winner = json.data[0];
            winnerAnnouncement.classList.remove("hidden");
            winnerName.textContent = winner.username;
            // FIX: Use SOL balance for final winner display instead of total
            winnerTotal.textContent = winner.sol.toFixed(4);
            winnerChange.textContent = winner.change_pct > 0 ? `+${winner.change_pct.toFixed(2)}` : winner.change_pct.toFixed(2);
          }

          // Update ranking mode display
          const isFinalRanking = json.final_ranking_by_sol_only || false;
          rankingInfo.classList.remove("hidden");
          if (isFinalRanking) {
            liveRanking.classList.add("hidden");
            finalRanking.classList.remove("hidden");
            totalHeader.textContent = "SOL Balance";
          } else {
            liveRanking.classList.remove("hidden");
            finalRanking.classList.add("hidden");
            totalHeader.textContent = "Total (SOL)";
          }

          // Generate both desktop table and mobile cards
          json.data.forEach((entry, i) => {
            const rank = i + 1;
            const changeColor = entry.change_pct > 0 ? 'text-green-400' : (entry.change_pct < 0 ? 'text-red-400' : 'text-gray-300');
            const changeText = entry.change_pct > 0 ? '▲' : (entry.change_pct < 0 ? '▼' : '–');
            
            // Use SOL balance for final ranking, total for normal ranking
            const displayTotal = isFinalRanking ? entry.sol : entry.total;
            
            // Desktop table row
            let rowClass = "hover:bg-gray-700 transition-colors";
            let rankDisplay = getRankEmoji(rank);
            
            if (rank === 1) {
              rowClass = "bg-gradient-to-r from-yellow-900 to-yellow-800 border-l-4 border-yellow-400";
              if (json.challenge_ended) rowClass += " winner-glow";
            } else if (rank === 2) {
              rowClass = "bg-gradient-to-r from-gray-800 to-gray-700 border-l-4 border-gray-400";
            } else if (rank === 3) {
              rowClass = "bg-gradient-to-r from-orange-900 to-orange-800 border-l-4 border-orange-400";
            } else {
              rowClass += i % 2 === 0 ? " bg-gray-800" : " bg-gray-700";
            }

            const row = document.createElement("tr");
            row.className = rowClass;
            row.innerHTML = `
              <td class="px-6 py-4 font-bold text-lg ${rank <= 3 ? 'text-2xl' : ''}">${rankDisplay}</td>
              <td class="px-6 py-4 ${rank === 1 ? 'font-bold text-yellow-200 text-lg' : rank <= 3 ? 'font-semibold' : ''}">${entry.username}</td>
              <td class="px-6 py-4 text-sm text-gray-300">
                <a href="https://solscan.io/account/${entry.wallet}" target="_blank" class="hover:text-blue-400 transition-colors">
                  ${entry.wallet.slice(0, 4)}...${entry.wallet.slice(-4)}
                </a>
              </td>
              <td class="px-6 py-4 text-right font-mono">${entry.sol.toFixed(4)}</td>
              <td class="px-6 py-4 text-right text-green-400 font-mono">${entry.tokens.toFixed(4)}</td>
              <td class="px-6 py-4 text-right font-bold ${rank === 1 ? 'text-yellow-200 text-xl' : rank <= 3 ? 'text-yellow-400 text-lg' : 'text-yellow-400'} font-mono">
                ${displayTotal.toFixed(4)}${isFinalRanking ? '<span class="text-xs text-red-300 block">🏁 SOL only</span>' : ''}
              </td>
              <td class="px-6 py-4 text-right font-mono ${changeColor} ${rank <= 3 ? 'font-bold' : ''}">${changeText} ${entry.change_pct.toFixed(2)}%</td>
            `;
            tbody.appendChild(row);

            // Mobile card
            mobileContainer.innerHTML += createMobileCard(entry, rank, json.challenge_ended, isFinalRanking);
          });
        })
        .catch(err => {
          document.getElementById("last-updated").textContent = "Fehler beim Laden!";
          console.error("Fehler beim Laden der API-Daten:", err);
        });
    }

    // Start the application
    updateLeaderboard();
    setInterval(updateLeaderboard, CONFIG.update_interval_seconds * 1000);
    
    // Update time display every minute
    setInterval(() => {
      const lastUpdatedElement = document.getElementById("last-updated");
      if (lastUpdatedElement.dataset.lastUpdate) {
        lastUpdatedElement.textContent = getTimeAgo(lastUpdatedElement.dataset.lastUpdate);
      }
    }, 60000);
  </script>

  <!-- Footer -->
  <footer class="mt-12 mb-6 w-full max-w-4xl">
    <div class="bg-gradient-to-r from-gray-800 via-gray-700 to-gray-800 rounded-xl p-6 shadow-2xl border border-gray-600">
      <div class="flex flex-col items-center justify-center space-y-4">
        
        <!-- Dev Info -->
        <div class="flex items-center space-x-3">
          <div class="text-center">
            <div class="text-sm font-semibold text-gray-300">Crafted with <span class="text-red-400">♥</span> by</div>
            <div class="text-lg font-bold gradient-text">BeardedViking</div>
          </div>
        </div>

        <!-- Update Info -->
        <div class="text-center">
          <div class="flex items-center justify-center space-x-2 text-sm text-gray-400">
            <span>Data updates every <?php echo $config['app']['update_interval_seconds']; ?>s • Page refreshes every 30sec</span>
          </div>
        </div>
      </div>
    </div>
  </footer>
</body>
</html>