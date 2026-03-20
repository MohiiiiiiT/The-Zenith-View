<?php
// Loads the specific CSS for this compact ad component
?>
<link rel="stylesheet" href="<?= rtrim(BASE_URL, '/') ?>/assets/css/event_banner.css?v=<?= time(); ?>">

<section class="section-promo" aria-label="Featured Hackathon Promotion">
    <div class="container">
        <div class="promo-ticket">
            
            <div class="promo-ticket-img-box">
                <img src="<?= rtrim(BASE_URL, '/') ?>/assets/images/aftermath-poster.jpg" alt="Aftermath 2026 Hackathon" class="promo-ticket-img">
            </div>
            
            <div class="promo-ticket-content">
                <div class="promo-header">
                    <span class="promo-badge-slim">LIVE EVENT</span>
                    <h2 class="promo-title">AFTERMATH 2026</h2>
                </div>
                
                <p class="promo-tagline">When systems collapse, innovators rise. Join the 24-hour offline hackathon.</p>
                
                <ul class="promo-meta">
                    <li><i class="bi bi-calendar-event"></i> 28 Feb — 1 Mar</li>
                    <li><i class="bi bi-geo-alt"></i> KJSSE, Mumbai</li>
                    <li><i class="bi bi-trophy"></i> ₹1 Lakh+ Prize Pool</li>
                </ul>

                <div class="promo-action-bar">
                    <a href="https://kjsce.acm.org/aftermath/" target="_blank" class="btn-promo">
                        Register Now <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </a>
                    <span class="promo-urgent"><i class="bi bi-hourglass-split"></i> Closes 22 Feb</span>
                </div>
            </div>

        </div>
    </div>
</section>