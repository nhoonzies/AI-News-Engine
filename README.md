# PHP AI Newsletter Engine
An automated data pipeline built with PHP that aggregates news from multiple Philippine sources (YugaTech, Philstar, ABS-CBN) and uses the **Gemini 2.5 Flash Lite** LLM to draft professional newsletters.

## 🚀 Features
- **Dynamic Aggregation**: Fetches and balances up to 6 articles across selected RSS feeds.
- **AI-Powered Drafting**: Uses Google Gemini to summarize and format technical updates.
- **Custom Scheduling**: Built-in "Infinity Loop" allows for automated runs at user-defined times.
- **Dark Mode UI**: Professional dashboard with real-time system diagnostics.

## 🛠️ Tech Stack
- **Backend**: PHP (PHPMailer, GuzzleHttp, Parsedown)
- **AI**: Google Gemini API
- **Frontend**: Vanilla HTML/CSS/JS

## 📦 Installation
1. Clone the repository.
2. Run `composer install` to fetch dependencies.
3. Create a `.env` file with the following:
   ```env
   GEMINI_API_KEY=your_key_here
   SENDER_EMAIL=your_gmail@gmail.com
   GMAIL_APP_PASSWORD=your_app_password
