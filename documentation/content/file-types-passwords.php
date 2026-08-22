<p>A quick reference for what PrimoAI's document scanner accepts, and how statement passwords typically work.</p>

<h2>Supported file types</h2>
<ul>
  <li><strong>PDF</strong> — the most common format for CAS, NSDL/CDSL, and broker statements.</li>
  <li><strong>JPG / PNG</strong> — a clear photo or screenshot of a statement or FD certificate.</li>
  <li><strong>XLSX</strong> — spreadsheet exports from some brokers.</li>
</ul>
<p>Maximum file size is 10 MB per upload. If your file is larger, try requesting a shorter date range from your RTA/broker, or compress the PDF before uploading.</p>

<h2>Password conventions</h2>
<ul>
  <li><strong>CAS (CAMS / KFintech)</strong> and <strong>NSDL / CDSL statements</strong> — usually your <strong>PAN number in capital letters</strong>.</li>
  <li>Some issuers combine PAN with your date of birth (format <code>DDMMYYYY</code>) — if the PAN alone doesn't work, check the delivery email for the exact format used.</li>
  <li>Broker-exported statements (Zerodha, Groww, etc.) are often not password protected at all.</li>
</ul>

<div class="doc-callout">
  <i class="bi bi-info-circle doc-callout-icon"></i>
  <p>Your password is only used once, at upload time, to decrypt the file for reading — it isn't stored.</p>
</div>
