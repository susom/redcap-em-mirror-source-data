# Mirror Master EM

Mirror Master EM will migrate data from one project to another. Mirror Master supports repeated instances which allow splitting data from one project into multiple projects or multiple events in the same project. Also it supports Data Access Group DAG mapping to migrate master DAG record to a Child record based on user access.  

###Configuration:
1. Master Project:
    1. Trigger Event.
    2. Trigger Logic(save will be triggered once this logic is true).
    3. Trigger Form. 
    4. Optional: Fields Form(If field copy option is Parent Form).
    5. Optional: Field to save Child Record Id.
    6. Field to save Migration timestamp. 
    7. Field to save Migration Notes. 
    
2. Child Project:
    1. Project Id.
    2. Trigger Event. 
    3. Optional: Fields Form(If field copy option is Child Form)
    4. Optional: Child Record Naming Option
    5. Optional: Child Record prefix option.
    6. Optional: Child Record pad length.
    7. Optional: Field to save parent record ID.
     
3. Global Configuration:
    1. Field Copy Option.
    2. DAG Configuration:
        1. Checkbox if dag names are the same between master and child so no mapping is needed. 
        2. DAG Map:
            1. Master DAG.
            2. Child DAG.
    3. Excluded Fields (based on Field Copy Option).
    4. REDO/Clobber Option(this will cause one master record to be saved multiple times in child).
    
    
    