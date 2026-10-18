(function () {
    'use strict';

    var form = document.querySelector('form.search[data-recherche-api], form.search[data-textes-api]');
    if (!form) {
        return;
    }

    var input = form.querySelector('#texte-search');
    var list = form.querySelector('#texte-suggestions');
    if (!input || !list) {
        return;
    }

    var apiUrl = form.getAttribute('data-recherche-api') || form.getAttribute('data-textes-api') || '/api/recherche';
    var urlBase = form.getAttribute('data-texte-url-base') || '/textes/__id__';
    var activeIndex = -1;
    var debounceTimer = null;

    function itemUrl(item) {
        if (item.url) {
            return item.url;
        }
        return urlBase.replace('__id__', encodeURIComponent(item.id));
    }

    function closeList() {
        list.innerHTML = '';
        activeIndex = -1;
        input.setAttribute('aria-expanded', 'false');
    }

    function renderItems(items) {
        list.innerHTML = '';
        activeIndex = -1;

        items.forEach(function (item) {
            var li = document.createElement('li');
            li.setAttribute('role', 'option');

            var a = document.createElement('a');
            a.href = itemUrl(item);

            if (item.type) {
                var type = document.createElement('span');
                type.className = 'search__type search__type--' + item.type;
                type.textContent = item.type;
                a.appendChild(type);
            }

            var titre = document.createElement('span');
            titre.className = 'search__titre';
            titre.textContent = item.label || item.titre || 'Sans titre';
            a.appendChild(titre);
            li.appendChild(a);

            var sub = item.sub || item.synthese;
            if (sub) {
                var synthese = document.createElement('span');
                synthese.className = 'synthese';
                synthese.textContent = sub;
                li.appendChild(synthese);
            }

            list.appendChild(li);
        });

        input.setAttribute('aria-expanded', items.length > 0 ? 'true' : 'false');
    }

    function highlight() {
        var links = list.querySelectorAll('a');
        links.forEach(function (link, index) {
            link.classList.toggle('active', index === activeIndex);
        });
        if (links[activeIndex]) {
            links[activeIndex].scrollIntoView({ block: 'nearest' });
        }
    }

    function fetchSuggestions(query) {
        fetch(apiUrl + '?q=' + encodeURIComponent(query))
            .then(function (response) {
                return response.ok ? response.json() : [];
            })
            .then(function (items) {
                if (input.value.trim() !== query) {
                    return;
                }
                renderItems(Array.isArray(items) ? items : []);
            })
            .catch(closeList);
    }

    input.addEventListener('input', function () {
        var query = input.value.trim();

        window.clearTimeout(debounceTimer);

        if (query === '') {
            closeList();
            return;
        }

        debounceTimer = window.setTimeout(function () {
            fetchSuggestions(query);
        }, 200);
    });

    input.addEventListener('keydown', function (event) {
        var links = list.querySelectorAll('a');

        if (event.key === 'ArrowDown' && links.length > 0) {
            event.preventDefault();
            activeIndex = (activeIndex + 1) % links.length;
            highlight();
        } else if (event.key === 'ArrowUp' && links.length > 0) {
            event.preventDefault();
            activeIndex = (activeIndex - 1 + links.length) % links.length;
            highlight();
        } else if (event.key === 'Enter' && activeIndex >= 0 && links[activeIndex]) {
            event.preventDefault();
            window.location.href = links[activeIndex].getAttribute('href');
        } else if (event.key === 'Escape') {
            closeList();
        }
    });

    input.addEventListener('blur', function () {
        window.setTimeout(closeList, 150);
    });

    document.addEventListener('click', function (event) {
        if (!form.contains(event.target)) {
            closeList();
        }
    });
})();
