from datetime import datetime

from flask import Flask, redirect, render_template, url_for

app = Flask(__name__)


@app.context_processor
def inject_year():
    return {"year": datetime.now().year}


@app.route("/")
def index():
    return render_template("index.html")


@app.route("/about")
def about():
    return redirect(url_for("index") + "#quienes")


if __name__ == "__main__":
    app.run(debug=True, port=5000)
