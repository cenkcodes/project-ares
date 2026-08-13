<footer class="site-footer">

    <div class="site-footer-inner">

        <div class="site-footer-top">

            <div class="site-footer-brand">

                <div class="site-footer-logo">
                    XURVEXA
                </div>

                <div class="site-footer-description">

                    Xurvexa is a video discovery platform
                    that organizes and presents embedded
                    video content from external sources.

                </div>

            </div>

            <nav
                class="site-footer-links"
                aria-label="Footer navigation"
            >

                <a href="{{ route('pages.about') }}">
                    About
                </a>

                <a href="{{ route('pages.contact') }}">
                    Contact
                </a>

                <a href="{{ route('pages.privacy') }}">
                    Privacy
                </a>

                <a href="{{ route('pages.terms') }}">
                    Terms
                </a>

                <a href="{{ route('pages.content-removal') }}">
                    Content Removal
                </a>

            </nav>

        </div>

        <div class="site-footer-bottom">

            <div>

                &copy;
                {{ date('Y') }}
                Xurvexa.
                All rights reserved.

            </div>

            <div class="adult-notice">

                18+ &middot;
                Adults only.
                Users must meet the minimum legal age
                required in their jurisdiction.

            </div>

        </div>

    </div>

</footer>
