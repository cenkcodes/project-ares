<header class="site-header">

    <div class="header-inner">

        <a
            class="logo"
            href="{{ route('home') }}"
        >
            PROJECT <span>ARES</span>
        </a>

        @if($showSearch ?? false)

            <form
                class="header-search"
                method="GET"
                action="{{ route('videos.index') }}"
            >

                <input
                    type="search"
                    name="q"
                    placeholder="Search videos..."
                    aria-label="Search videos"
                >

                <button type="submit">
                    Search
                </button>

            </form>

        @endif

        <nav class="main-nav">

            <a href="{{ route('home') }}">
                Home
            </a>

            <a href="{{ route('videos.index') }}">
                Videos
            </a>

        </nav>

    </div>

</header>
