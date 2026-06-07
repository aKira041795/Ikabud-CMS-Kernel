#!/usr/bin/env python3
"""
weather-service — Polyglot capability provider (Python)

Implements the Kernel OS capability wire protocol:
  POST /capability/call  →  {"capability_id":"...", "payload":{...}, "caller":{...}}
  Response: {"ok": true, "data": {...}} or {"ok": false, "error": "..."}

Capabilities:
  weather.current@1   — current conditions for a city
  weather.forecast@1  — 3-day forecast

Data source: wttr.in (free, no API key) with graceful fallback to mock data.

Start: python3 service.py
Port:  9002
"""

import json
import os
import sys
import urllib.request
import urllib.error
from http.server import HTTPServer, BaseHTTPRequestHandler

PORT = int(os.environ.get("WEATHER_SERVICE_PORT", 9002))
HOST = os.environ.get("WEATHER_SERVICE_HOST", "127.0.0.1")


def fetch_weather(city: str) -> dict:
    """Fetch current weather from wttr.in — free, no API key."""
    encoded = urllib.request.quote(city)
    url = f"https://wttr.in/{encoded}?format=j1"

    try:
        req = urllib.request.Request(url, headers={"User-Agent": "Ikabud-WeatherService/1.0"})
        with urllib.request.urlopen(req, timeout=5) as resp:
            raw = json.loads(resp.read().decode("utf-8"))
    except Exception as e:
        print(f"[weather-service] wttr.in failed: {e}, using mock data", file=sys.stderr)
        return _mock_weather(city)

    try:
        current = raw["current_condition"][0]
        return {
            "city": city,
            "temperature_c": float(current["temp_C"]),
            "condition": current["weatherDesc"][0]["value"],
            "humidity": int(current["humidity"]),
            "wind_kph": float(current["windspeedKmph"]),
            "feels_like_c": float(current["FeelsLikeC"]),
            "source": "wttr.in",
        }
    except (KeyError, IndexError, ValueError) as e:
        print(f"[weather-service] parse error: {e}", file=sys.stderr)
        return _mock_weather(city)


def fetch_forecast(city: str, days: int = 3) -> dict:
    """Fetch forecast from wttr.in."""
    encoded = urllib.request.quote(city)
    url = f"https://wttr.in/{encoded}?format=j1"

    try:
        req = urllib.request.Request(url, headers={"User-Agent": "Ikabud-WeatherService/1.0"})
        with urllib.request.urlopen(req, timeout=5) as resp:
            raw = json.loads(resp.read().decode("utf-8"))
    except Exception as e:
        print(f"[weather-service] wttr.in failed: {e}, using mock data", file=sys.stderr)
        return _mock_forecast(city, days)

    try:
        forecast_days = raw["weather"][:days]
        forecast = []
        for day in forecast_days:
            forecast.append({
                "date": day["date"],
                "high_c": float(day["maxtempC"]),
                "low_c": float(day["mintempC"]),
                "condition": day["hourly"][4]["weatherDesc"][0]["value"],
            })
        return {"city": city, "forecast": forecast, "source": "wttr.in"}
    except (KeyError, IndexError, ValueError) as e:
        print(f"[weather-service] parse error: {e}", file=sys.stderr)
        return _mock_forecast(city, days)


def _mock_weather(city: str) -> dict:
    return {
        "city": city,
        "temperature_c": 22.0,
        "condition": "Partly cloudy",
        "humidity": 65,
        "wind_kph": 12.0,
        "feels_like_c": 21.0,
        "source": "mock",
    }


def _mock_forecast(city: str, days: int = 3) -> dict:
    return {
        "city": city,
        "forecast": [
            {"date": "2026-06-08", "high_c": 24, "low_c": 16, "condition": "Sunny"},
            {"date": "2026-06-09", "high_c": 22, "low_c": 15, "condition": "Partly cloudy"},
            {"date": "2026-06-10", "high_c": 26, "low_c": 18, "condition": "Clear"},
        ][:days],
        "source": "mock",
    }


CAPABILITY_HANDLERS = {
    "weather.current@1": lambda payload: fetch_weather(str(payload.get("city", "London"))),
    "weather.forecast@1": lambda payload: fetch_forecast(
        str(payload.get("city", "London")),
        int(payload.get("days", 3)),
    ),
}


class CapabilityHandler(BaseHTTPRequestHandler):
    def log_message(self, format, *args):
        print(f"[weather-service] {args[0]}", file=sys.stderr)

    def _json_response(self, status: int, body: dict):
        data = json.dumps(body).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        if self.path == "/health":
            self._json_response(200, {"ok": True, "service": "weather-service", "version": "1.0.0"})
        else:
            self._json_response(404, {"ok": False, "error": "not found"})

    def do_POST(self):
        if self.path != "/capability/call":
            self._json_response(404, {"ok": False, "error": "only /capability/call is supported"})
            return

        content_length = int(self.headers.get("Content-Length", 0))
        if content_length == 0:
            self._json_response(400, {"ok": False, "error": "empty body"})
            return

        try:
            body = json.loads(self.rfile.read(content_length))
        except json.JSONDecodeError as e:
            self._json_response(400, {"ok": False, "error": f"invalid JSON: {e}"})
            return

        capability_id = body.get("capability_id", "")
        payload = body.get("payload", {})
        caller = body.get("caller", {})

        print(
            f"[weather-service] capability={capability_id} caller_module={caller.get('module', '?')}",
            file=sys.stderr,
        )

        handler = CAPABILITY_HANDLERS.get(capability_id)
        if handler is None:
            self._json_response(404, {
                "ok": False,
                "error": f"unknown capability: {capability_id}",
                "available": list(CAPABILITY_HANDLERS.keys()),
            })
            return

        try:
            result = handler(payload)
            self._json_response(200, {"ok": True, "data": result})
        except Exception as e:
            print(f"[weather-service] handler error: {e}", file=sys.stderr)
            self._json_response(500, {"ok": False, "error": str(e)})


if __name__ == "__main__":
    server = HTTPServer((HOST, PORT), CapabilityHandler)
    print(f"[weather-service] Listening on http://{HOST}:{PORT}")
    print(f"[weather-service] Capabilities: {list(CAPABILITY_HANDLERS.keys())}")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\n[weather-service] Shutting down")
        server.shutdown()
