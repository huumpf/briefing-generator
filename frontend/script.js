document.addEventListener('DOMContentLoaded', () => {
    const websiteForm = document.getElementById('website-form');
    const step1 = document.getElementById('step-1');
    const step2 = document.getElementById('step-2');
    const step3 = document.getElementById('step-3');
    const step4 = document.getElementById('step-4');
    const briefingIdeas = document.getElementById('briefing-ideas');
    const fullBriefing = document.getElementById('full-briefing');
    const selectedIdeaTitle = document.getElementById('selected-idea-title');
    const publishBriefing = document.getElementById('publish-briefing');
    const errorMessage = document.getElementById('error-message');

    let currentAnalysis = null;

    websiteForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const websiteUrl = document.getElementById('website-url').value;
        
        showStep(2);
        hideErrorMessage();

        try {
            const response = await analyzeWebsite(websiteUrl);
            console.log('Server response:', response);

            if (response && Array.isArray(response.ideas) && response.ideas.length > 0) {
                currentAnalysis = response.analysis;
                displayBriefingIdeas(response.ideas);
                showStep(3);
            } else {
                throw new Error('Keine gültigen Ideen vom Server erhalten');
            }
        } catch (error) {
            console.error('Error:', error);
            showErrorMessage('Ein Fehler ist aufgetreten: ' + error.message);
            showStep(1);
        }
    });

    function displayBriefingIdeas(ideas) {
        briefingIdeas.innerHTML = '';
        ideas.forEach((idea, index) => {
            const ideaElement = document.createElement('div');
            ideaElement.classList.add('briefing-idea');
            ideaElement.innerHTML = `
                <h3>${idea.title || 'Unbenannte Idee ' + (index + 1)}</h3>
                <p>${idea.description}</p>
                <p><strong>Empfohlene Dauer:</strong> ${idea.duration || 'Nicht angegeben'}</p>
                <p><strong>Passende Kanäle:</strong> ${idea.channels || 'Nicht angegeben'}</p>
            `;
            ideaElement.addEventListener('click', () => selectIdea(idea, index));
            briefingIdeas.appendChild(ideaElement);
        });
        
        // Log the received ideas for debugging
        console.log("Received ideas:", ideas);
    }

    function safeDecodeString(str) {
        try {
            return decodeURIComponent(JSON.parse('"' + str.replace(/\"/g, '\\"') + '"'));
        } catch (e) {
            console.error("Fehler beim Dekodieren des Strings:", str, e);
            return str;
        }
    }

    async function selectIdea(idea, index) {
        showStep(4);
        
        selectedIdeaTitle.textContent = safeDecodeString(idea.title) || 'Ausgewählte Idee';
        fullBriefing.innerHTML = '<p>Vollständiges Briefing wird geladen...</p>';
        
        try {
            const briefingData = await getFullBriefing(index, currentAnalysis, idea);
            if (briefingData && briefingData.briefing) {
                fullBriefing.innerHTML = briefingData.briefing;
            } else {
                throw new Error('Ungültige Briefing-Daten erhalten');
            }
        } catch (error) {
            console.error('Error:', error);
            showErrorMessage('Fehler beim Laden des vollständigen Briefings: ' + error.message);
        }
    }
    
    async function getFullBriefing(index, analysis, idea) {
        const response = await fetch('/backend/server.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ ideaIndex: index, analysis, idea }),
        });
        
        if (!response.ok) {
            const errorData = await response.json();
            throw new Error(errorData.error || 'Network response was not ok');
        }
        
        const data = await response.json();
        return data;
    }

    publishBriefing.addEventListener('click', () => {
        alert('Briefing für Creator veröffentlicht!');
    });

    async function analyzeWebsite(url) {
        try {
            const response = await fetch('/backend/server.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ url }),
            });
            
            const responseText = await response.text();
            console.log('Raw server response:', responseText);
            
            if (!response.ok) {
                let errorMessage;
                try {
                    const errorData = JSON.parse(responseText);
                    errorMessage = errorData.error || 'Network response was not ok';
                } catch (e) {
                    errorMessage = 'Failed to parse error response';
                }
                throw new Error(errorMessage);
            }
            
            const data = JSON.parse(responseText);
            console.log('Parsed server response:', data);
            return data;
        } catch (error) {
            console.error('Error in analyzeWebsite:', error);
            throw error;
        }
    }

    function showStep(stepNumber) {
        [step1, step2, step3, step4].forEach((step, index) => {
            step.classList.toggle('active', index + 1 === stepNumber);
        });
    }

    function showErrorMessage(message) {
        errorMessage.textContent = message;
        errorMessage.style.display = 'block';
    }

    function hideErrorMessage() {
        errorMessage.style.display = 'none';
    }
});