<?php
// =============================================================================
// THE ZENITH VIEW — /includes/footer.php
// Reusable 4-column footer + JS scripts.
// Must be the last include on every page, closing </main> is in the page file.
// =============================================================================
?>

<!-- ===========================================================================
     BACK TO TOP BUTTON (fixed, revealed by JS on scroll)
=========================================================================== -->
<button class="back-to-top" id="backToTop" aria-label="Scroll back to top">
    <i class="bi bi-arrow-up" aria-hidden="true"></i>
</button>

<!-- ===========================================================================
     FOOTER — 4-column desktop / stacked centered mobile
=========================================================================== -->
<footer class="site-footer" role="contentinfo">
    <div class="container">

        <!-- 4-column grid -->
        <div class="row g-4 footer-main">

            <!-- Col 1: Brand & Social -->
            <div class="col-12 col-sm-6 col-lg-4">
                <div class="footer-brand-group">
                    <div class="footer-logo-mark" aria-hidden="true">
                        <i class="bi bi-trophy-fill"></i>
                    </div>
                    <div>
                        <div class="footer-brand-name"><?= htmlspecialchars(APP_NAME,    ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="footer-brand-tag">by KJSCE</div>
                    </div>
                </div>
                <p class="footer-description">
                    The official academic achievement leaderboard for
                    K.J. Somaiya College of Engineering. Merit made visible.
                </p>
                <div class="footer-social" aria-label="Social media links">
                    <a href="#" class="social-icon" aria-label="LinkedIn"  title="LinkedIn"><i  class="bi bi-linkedin"  aria-hidden="true"></i></a>
                    <a href="#" class="social-icon" aria-label="Instagram" title="Instagram"><i class="bi bi-instagram" aria-hidden="true"></i></a>
                    <a href="#" class="social-icon" aria-label="GitHub"    title="GitHub"><i    class="bi bi-github"    aria-hidden="true"></i></a>
                    <a href="#" class="social-icon" aria-label="YouTube"   title="YouTube"><i   class="bi bi-youtube"   aria-hidden="true"></i></a>
                </div>
            </div>

            <!-- Col 2: Platform Links -->
            <div class="col-6 col-sm-3 col-lg-2 offset-lg-1">
                <h3 class="footer-col-heading">Platform</h3>
                <ul class="footer-link-list" role="list">
                    <li><a href="<?= BASE_URL ?>/index.php"       class="footer-link">Home</a></li>
                    <li><a href="<?= BASE_URL ?>/leaderboard.php" class="footer-link">Leaderboard</a></li>
                    <li><a href="<?= BASE_URL ?>/login.php"       class="footer-link">Student Login</a></li>
                    <li><a href="<?= BASE_URL ?>/login.php"       class="footer-link">Faculty Login</a></li>
                </ul>
            </div>

            <!-- Col 3: Support Links -->
            <div class="col-6 col-sm-3 col-lg-2">
                <h3 class="footer-col-heading">Support</h3>
                <ul class="footer-link-list" role="list">
                    <li><a href="<?= BASE_URL ?>/submit_ticket.php" class="footer-link">Help Desk</a></li>
                    <li><a href="<?= BASE_URL ?>/submit_ticket.php" class="footer-link">Report an Issue</a></li>
                    <li><a href="#"                                  class="footer-link">Scoring Guide</a></li>
                    <li><a href="#"                                  class="footer-link">FAQs</a></li>
                </ul>
            </div>

            <!-- Col 4: Institution Info -->
            <div class="col-12 col-sm-6 col-lg-3">
                <h3 class="footer-col-heading">Institution</h3>
                <address class="footer-address">
                    <p>
                        <i class="bi bi-building footer-addr-icon" aria-hidden="true"></i>
                        <?= htmlspecialchars(APP_COLLEGE, ENT_QUOTES, 'UTF-8') ?>
                    </p>
                    <p>
                        <i class="bi bi-geo-alt footer-addr-icon" aria-hidden="true"></i>
                        Vidyanagar, Vidyavihar (E),<br>Mumbai &mdash; 400077
                    </p>
                    <p>
                        <i class="bi bi-globe footer-addr-icon" aria-hidden="true"></i>
                        <a href="https://kjsce.somaiya.edu" target="_blank" rel="noopener noreferrer" class="footer-link">
                            kjsce.somaiya.edu
                        </a>
                    </p>
                </address>
            </div>

        </div><!-- /row footer-main -->

        <!-- Bottom bar -->
        <div class="footer-bottom">
            <div class="row align-items-center g-2">
                <div class="col-12 col-md-6 text-center text-md-start">
                    <span class="footer-copy">
                        &copy; <?= date('Y') ?> K.J. Somaiya College of Engineering. All rights reserved.
                    </span>
                </div>
                <div class="col-12 col-md-6 text-center text-md-end">
                    <span class="footer-copy">
                        For internal institutional use only &mdash; not for public distribution.
                    </span>
                </div>
            </div>
        </div>

    </div><!-- /.container -->
</footer><!-- /site-footer -->


<!-- ===========================================================================
     JAVASCRIPT
     Bootstrap integrity hash removed — causes console errors when the CDN
     serves a file that doesn't exactly match the cached hash (e.g. on some
     shared hosts / proxies). Removing it is safe for development; add back
     once you confirm the exact hash matches your deployment environment.
=========================================================================== -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/assets/js/main.js"></script>

</body>
</html>