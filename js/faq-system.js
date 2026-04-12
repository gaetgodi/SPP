/**
 * FAQ System JavaScript - REST API Version
 * Complete, production-ready file
 * 
 * DELETE EVERYTHING in faq-system.js and paste ONLY this file
 */

(function() {
    'use strict';
    
    const restApiUrl = '/wp-json/wp/v2/faqs?per_page=100';
    
    let faqData = [];
    let categories = new Set(['all']);

    async function loadFAQs() {
        try {
            const response = await fetch(restApiUrl);
            
            if (!response.ok) {
                throw new Error('Failed to load FAQs: ' + response.status);
            }
            
            const faqs = await response.json();
            
            faqData = faqs.map(function(faq) {
                const category = faq.category_names && faq.category_names.length > 0 
                    ? faq.category_names[0] 
                    : 'Uncategorized';
                
                categories.add(category);
                
                return {
                    question: faq.title.rendered,
                    answer: faq.answer || faq.content.rendered,
                    category: category
                };
            });
            
            if (faqData.length === 0) {
                document.getElementById('faqContainer').innerHTML = 
                    '<div style="color: orange; padding: 20px;">No FAQs found.</div>';
                return;
            }
            
            buildCategoryDropdown();
            renderFAQs();
            filterByCategory('all');
            
        } catch (error) {
            document.getElementById('faqContainer').innerHTML = 
                '<div style="color: red; padding: 20px;">Error loading FAQs: ' + error.message + '</div>';
            console.error('FAQ Load Error:', error);
        }
    }

    function buildCategoryDropdown() {
        const dropdown = document.getElementById('categorySelect');
        
        // Clear all options except "All Categories"
        while (dropdown.options.length > 1) {
            dropdown.remove(1);
        }
        
        const sortedCategories = Array.from(categories)
            .filter(function(cat) {
                return cat && cat !== 'all';
            })
            .sort();
        
        sortedCategories.forEach(function(category) {
            const option = document.createElement('option');
            option.value = category;
            option.textContent = category;
            dropdown.appendChild(option);
        });
        
        dropdown.addEventListener('change', function() {
            filterByCategory(dropdown.value);
        });
    }

    function filterByCategory(category) {
        const faqItems = document.querySelectorAll('.faq-item');
        let visibleCount = 0;
        
        faqItems.forEach(function(item) {
            const itemCategory = item.getAttribute('data-category');
            
            if (category === 'all' || itemCategory === category) {
                item.classList.remove('hidden');
                item.style.display = 'block';
                visibleCount++;
            } else {
                item.classList.add('hidden');
                item.style.display = 'none';
                
                const answer = item.querySelector('.faq-answer');
                if (answer) {
                    answer.classList.remove('active');
                }
                item.classList.remove('active');
            }
        });
        
        const noResults = document.getElementById('noResults');
        if (visibleCount === 0) {
            noResults.classList.add('visible');
        } else {
            noResults.classList.remove('visible');
        }
    }

    function renderFAQs() {
        const container = document.getElementById('faqContainer');
        container.innerHTML = '';
        
        faqData.forEach(function(faq) {
            const faqItem = document.createElement('div');
            faqItem.className = 'faq-item';
            faqItem.setAttribute('data-category', faq.category);
            
            faqItem.innerHTML = 
                '<div class="faq-question">' +
                    '<div class="q-icon"></div>' +
                    '<div class="question-text">' + faq.question + '</div>' +
                '</div>' +
                '<div class="faq-answer">' +
                    '<div class="faq-answer-content">' + faq.answer + '</div>' +
                '</div>';
            
            const questionDiv = faqItem.querySelector('.faq-question');
            const answerDiv = faqItem.querySelector('.faq-answer');
            
            questionDiv.addEventListener('click', function() {
                const isActive = answerDiv.classList.contains('active');
                
                document.querySelectorAll('.faq-answer.active').forEach(function(answer) {
                    answer.classList.remove('active');
                });
                document.querySelectorAll('.faq-item.active').forEach(function(item) {
                    item.classList.remove('active');
                });
                
                if (!isActive) {
                    answerDiv.classList.add('active');
                    faqItem.classList.add('active');
                }
            });
            
            container.appendChild(faqItem);
        });
    }

    function init() {
        if (document.getElementById('faqContainer')) {
            loadFAQs();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();