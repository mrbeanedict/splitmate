<?php
// Setlo's Terms & Conditions and Privacy Policy, linked from the sign-up checkbox (pages/register.php).
require __DIR__ . '/../includes/bootstrap.php';
$title = 'Terms & Privacy';
$bodyClass = 'legal-body';
$headExtra = ['assets/css/legal.css'];
require __DIR__ . '/../partials/head.php';
?>
<main class="legal">
  <a class="legal-back" href="register" onclick="if (history.length > 1) { history.back(); return false; }">← Back</a>
  <div class="legal-card">
    <h1>Terms &amp; Conditions and Privacy Policy</h1>
    <p class="legal-meta">Setlo · Last updated October 14, 2026</p>
    <nav aria-label="On this page"><a href="#terms">Terms &amp; Conditions</a><a href="#privacy">Privacy Policy</a></nav>

    <h2 id="terms">Terms &amp; Conditions</h2>
    <p>These terms apply when you create a Setlo account or use the Setlo app. By ticking “I agree” at sign-up, you confirm you have read and accepted them.</p>

    <h3>1. What Setlo is</h3>
    <p>Setlo is a tool for splitting shared expenses (such as restaurant bills and utang) among friends. It works out who owes whom and helps you record and confirm payments. Setlo is <b>not a bank, e-wallet or payment institution</b>, and it does not hold your money. Payments happen between you and the other person through GCash, Maya, bank transfer, cash or the online payment option shown in the app.</p>

    <h3>2. Your account</h3>
    <ul>
      <li>You must provide accurate information, and be old enough to enter into an agreement where you live (or have a parent or guardian’s permission).</li>
      <li>Your username must be unique and must not impersonate someone else or be offensive.</li>
      <li>Keep your password private. You are responsible for activity on your account, so tell the app manager if you think it was accessed without permission.</li>
      <li>One person, one account. Guest mode is for trying the app and does not save anything.</li>
    </ul>

    <h3>3. Bills, amounts and payments</h3>
    <ul>
      <li>Setlo’s totals depend on what you and others enter. Scanned receipts are read automatically and can contain mistakes, so please check items, quantities, tax, service charge and discounts before you settle.</li>
      <li>A payment marked as paid, confirmed or disputed in the app is a record between users. Setlo cannot force anyone to pay and is not responsible for money that is not paid or is sent to the wrong person or account.</li>
      <li>Always check the payment details (account name and number) before sending money.</li>
      <li>Online payments are handled by a third-party payment provider and are also subject to that provider’s terms.</li>
    </ul>

    <h3>4. Acceptable use</h3>
    <p>Do not use Setlo to:</p>
    <ul>
      <li>record false bills or payments, harass or defraud others, or attempt to collect money that is not owed;</li>
      <li>post unlawful, hateful, sexual or misleading content, including in the community area, photos and reviews;</li>
      <li>upload files you do not have the right to share, or anything harmful to the service or other users;</li>
      <li>try to break, overload, reverse-engineer or gain unauthorised access to the app or other accounts.</li>
    </ul>

    <h3>5. Your content</h3>
    <p>You keep ownership of what you add (bills, notes, photos, posts, reviews). You allow Setlo to store, process and display it as needed to run the app, for example showing a bill to the people in it. Content you post publicly, such as community posts or reviews, may be seen by other users.</p>

    <h3>6. Suspension and removal</h3>
    <p>The app manager may remove content, or suspend or close an account, that breaks these terms or puts other users at risk. You may stop using Setlo at any time and may ask for your account to be deleted (see the Privacy Policy).</p>

    <h3>7. Availability and liability</h3>
    <p>Setlo is provided “as is”. We try to keep it running and accurate but do not promise it will always be available or error-free. To the extent the law allows, Setlo and its makers are not liable for losses arising from use of the app, including incorrect splits, unpaid amounts, or downtime.</p>

    <h3>8. Changes and governing law</h3>
    <p>We may update these terms from time to time. The “last updated” date above shows the latest version, and continuing to use Setlo after a change means you accept it. These terms are governed by the laws of the Republic of the Philippines.</p>

    <h2 id="privacy">Privacy Policy</h2>
    <p>Setlo respects your privacy and handles personal information in line with the Philippine Data Privacy Act of 2012 (Republic Act No. 10173). This section explains what we collect, why, and the choices you have.</p>

    <h3>1. Information we collect</h3>
    <ul>
      <li><b>Account details:</b> your full name, username, email address and a securely hashed (never plain-text) password. If you use “Continue with Google”, we receive your name and email from Google.</li>
      <li><b>Profile and payment details you choose to add:</b> profile picture or background photo, preferred payment method and account or GCash number shown to people who owe you, and your home currency.</li>
      <li><b>Bills and activity:</b> bills, items, who ordered what, amounts, settlements, payment proofs, notes, disputes, groups and the people you add. Guests added to a bill are stored by the name you enter.</li>
      <li><b>Receipt photos:</b> images you upload for scanning.</li>
      <li><b>Community content:</b> posts, photos, ratings and reviews you submit.</li>
      <li><b>Technical data:</b> IP address and sign-in attempts (for security and to limit password guessing), a session cookie to keep you signed in, and settings stored in your browser such as theme and sidebar.</li>
    </ul>

    <h3>2. How we use it</h3>
    <ul>
      <li>To create and secure your account and let you sign in with your username or email.</li>
      <li>To split bills, track who owes whom, and show payment details to the right people.</li>
      <li>To send in-app notifications and, when you ask, password-reset emails.</li>
      <li>To read receipts, show achievements and community features, and keep the service safe from abuse.</li>
    </ul>
    <p>We do not sell your personal information.</p>

    <h3>3. Who can see your information</h3>
    <ul>
      <li><b>Other users in your bills:</b> your name, username, profile picture and the amounts, items and payment details relevant to that bill.</li>
      <li><b>Everyone, if you post publicly:</b> community posts, photos and reviews.</li>
      <li><b>The app manager:</b> to run the service, handle disputes and moderate content.</li>
    </ul>

    <h3>4. Services that help us run Setlo</h3>
    <ul>
      <li><b>Google Sign-In</b>, if you choose to use it.</li>
      <li><b>Google Gemini</b>, which reads the receipt photos you scan. Receipt photos may be processed under Google’s own terms, so avoid scanning receipts that show information you would not want shared.</li>
      <li><b>PayMongo</b>, a payment provider, when you pay online. Card and e-wallet details are entered with the provider and are not stored by Setlo.</li>
      <li>Email delivery, for password-reset codes.</li>
    </ul>

    <h3>5. How long we keep it, and security</h3>
    <p>We keep your information while your account is active and as long as needed for the bills and payment records you share with others. Passwords are hashed, sign-in attempts are rate-limited, and access to data is restricted. No system is completely secure, so please use a strong password and keep it private.</p>

    <h3>6. Your rights and choices</h3>
    <p>Under the Data Privacy Act you may ask to access, correct or delete your personal information, object to certain uses, and withdraw consent. You can update your profile and payment details in the app. To request deletion or a copy of your data, contact the app manager (for example through the feedback option in the app). Some records, such as a bill that other people are part of, may need to be kept so their records stay correct.</p>

    <h3>7. Children</h3>
    <p>Setlo is not meant for young children. If you believe a child has created an account without a parent or guardian’s permission, tell the app manager so it can be removed.</p>

    <h3>8. Changes</h3>
    <p>If we change this policy, the “last updated” date at the top will change, and for significant changes we will do our best to let you know in the app.</p>

    <p class="legal-note">Questions about these terms or your data? Reach the Setlo app manager through the feedback option in the app.</p>
  </div>
</main>
<?php require __DIR__ . '/../partials/foot.php'; ?>
