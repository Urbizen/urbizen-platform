# -*- coding: utf-8 -*-
"""
Microservice de constitution des dossiers d'urbanisme.

Reçoit un formulaire WordPress (multipart) et produit un dossier PDF unique :
   Cerfa rempli + notice descriptive + bordereau + pièces jointes.
Gère deux types via le champ `dossier_type` : "dp" (défaut) ou "pcmi".

Lancement :
    python app.py                                  # dev
    waitress-serve --port=8000 app:app             # prod Windows
    gunicorn -w 2 -b 127.0.0.1:8000 app:app        # prod Linux

Env optionnelles : CERFA_DP, CERFA_PCMI, DOSSIER_DIR, ALLOW_ORIGIN,
                   NOTIFY_TO, SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS
"""
import os
import io
import re
import smtplib
from datetime import datetime
from email.message import EmailMessage

from flask import Flask, request, jsonify, send_file
from flask_cors import CORS

import documents
import cerfa

DOSSIER_DIR = os.environ.get("DOSSIER_DIR", "dossiers")
ALLOW_ORIGIN = os.environ.get("ALLOW_ORIGIN", "*")
CERFA_FILES = {
    "dp":   os.environ.get("CERFA_DP", "cerfa_16702-02.pdf"),
    "pcmi": os.environ.get("CERFA_PCMI", "cerfa_13406-16.pdf"),
}

app = Flask(__name__)
CORS(app, resources={r"/api/*": {"origins": ALLOW_ORIGIN}})
app.config["MAX_CONTENT_LENGTH"] = 80 * 1024 * 1024  # 80 Mo
os.makedirs(DOSSIER_DIR, exist_ok=True)

REQUIRED = ["email", "terrain_adresse", "cad_section", "cad_numero", "nature"]


def _slug(s: str) -> str:
    s = re.sub(r"[^\w\-]+", "-", (s or "").strip().lower())
    return re.sub(r"-+", "-", s).strip("-") or "dossier"


def _image_to_pdf(raw: bytes):
    try:
        from PIL import Image
        from reportlab.lib.pagesizes import A4
        from reportlab.pdfgen import canvas
        from reportlab.lib.utils import ImageReader
        img = Image.open(io.BytesIO(raw)).convert("RGB")
        buf = io.BytesIO()
        c = canvas.Canvas(buf, pagesize=A4)
        pw, ph = A4
        iw, ih = img.size
        scale = min((pw - 40) / iw, (ph - 40) / ih)
        w, h = iw * scale, ih * scale
        tmp = io.BytesIO(); img.save(tmp, format="JPEG", quality=85); tmp.seek(0)
        c.drawImage(ImageReader(tmp), (pw - w) / 2, (ph - h) / 2, w, h)
        c.showPage(); c.save()
        return buf.getvalue()
    except Exception:
        return None


@app.get("/api/health")
def health():
    return jsonify(ok=True, cerfa={k: os.path.exists(v) for k, v in CERFA_FILES.items()})


@app.post("/api/dp")
def create_dossier():
    data = {k: v for k, v in request.form.items()}
    dossier_type = data.get("dossier_type", "dp")
    if dossier_type not in ("dp", "pcmi"):
        dossier_type = "dp"

    missing = [f for f in REQUIRED if not str(data.get(f, "")).strip()]
    if missing:
        return jsonify(ok=False, error="Champs manquants : " + ", ".join(missing)), 400

    # ---- pièces jointes ----
    piece_pdfs, pieces_fournies = [], []
    file_lists = request.files.lists() if hasattr(request.files, "lists") else []
    for field, files in file_lists:
        if not field.startswith("piece_"):
            continue
        code = field.replace("piece_", "")
        got = False
        for fs in files:
            raw = fs.read()
            if not raw:
                continue
            if fs.filename.lower().endswith(".pdf"):
                piece_pdfs.append(raw); got = True
            else:
                pdf = _image_to_pdf(raw)
                if pdf:
                    piece_pdfs.append(pdf); got = True
        if got:
            pieces_fournies.append(code)

    # ---- Cerfa (si présent + mappé) ----
    cerfa_pdf = None
    profile = cerfa.PROFILES[dossier_type]
    _, mapping, checkboxes = profile
    cerfa_path = CERFA_FILES[dossier_type]
    if os.path.exists(cerfa_path):
        try:
            cerfa_pdf = cerfa.fill_cerfa(data, cerfa_path, mapping, checkboxes)
        except Exception as e:
            app.logger.warning("Remplissage Cerfa (%s) ignoré : %s", dossier_type, e)

    # ---- pièces rédigées ----
    notice = documents.build_notice(data, dossier_type)
    # la notice générée EST la pièce descriptive du dossier
    notice_code = "PCMI4" if dossier_type == "pcmi" else "DP-notice"
    if notice_code not in pieces_fournies:
        pieces_fournies.append(notice_code)
    bordereau = documents.build_bordereau(data, pieces_fournies, dossier_type)

    # ---- assemblage : Cerfa · bordereau · notice · pièces ----
    parts = [p for p in [cerfa_pdf, bordereau, notice, *piece_pdfs] if p]
    dossier = documents.assemble_dossier(parts)

    # ---- sauvegarde ----
    who = data.get("nom") or data.get("denomination") or "client"
    stamp = datetime.now().strftime("%Y%m%d-%H%M%S")
    prefix = "PC" if dossier_type == "pcmi" else "DP"
    fname = "%s_%s_%s.pdf" % (prefix, _slug(who), stamp)
    with open(os.path.join(DOSSIER_DIR, fname), "wb") as f:
        f.write(dossier)

    if os.environ.get("NOTIFY_TO"):
        try:
            _notify(data, dossier, fname, dossier_type)
        except Exception as e:
            app.logger.warning("Envoi e-mail échoué : %s", e)

    return send_file(io.BytesIO(dossier), mimetype="application/pdf",
                     as_attachment=True, download_name=fname)


def _notify(data, dossier, fname, dossier_type):
    label = "PC" if dossier_type == "pcmi" else "DP"
    msg = EmailMessage()
    msg["Subject"] = "Nouveau dossier %s — %s (%s)" % (
        label, data.get("nom") or data.get("denomination") or "client",
        data.get("terrain_ville") or "")
    msg["From"] = os.environ["SMTP_USER"]
    msg["To"] = os.environ["NOTIFY_TO"]
    resume = "\n".join("%s : %s" % (k, v) for k, v in data.items())
    msg.set_content("Nouvelle demande (%s).\n\n%s" % (label, resume))
    msg.add_attachment(dossier, maintype="application", subtype="pdf", filename=fname)
    with smtplib.SMTP(os.environ.get("SMTP_HOST", "localhost"),
                      int(os.environ.get("SMTP_PORT", "587"))) as s:
        s.starttls()
        s.login(os.environ["SMTP_USER"], os.environ["SMTP_PASS"])
        s.send_message(msg)


if __name__ == "__main__":
    app.run(host="127.0.0.1", port=8000, debug=True)
