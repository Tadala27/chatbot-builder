// utils/scorecardUtils.ts - FIXED VERSION

import ExcelJS from "exceljs";

// ────────────────────────────────────────────────
// Color & UI Helpers
// ────────────────────────────────────────────────
export const getPerspectiveColor = (code: string): string => {
  const colors: Record<string, string> = {
    FIN: "success",
    CUST: "info",
    INT: "warning",
    "L&G": "purple",
  };
  return colors[code] || "primary";
};

export const getThresholdColor = (thresholdName: string): string => {
  const name = thresholdName.toLowerCase();
  if (name.includes("stretch") || name.includes("excellence")) return "success";
  if (name.includes("standard") || name.includes("target")) return "primary";
  if (name.includes("threshold") || name.includes("minimum")) return "warning";
  return "default";
};

// ────────────────────────────────────────────────
// Target & Objective Parsing / Formatting
// ────────────────────────────────────────────────
export const allTargetsEqual = (targets: any[]): boolean => {
  if (!targets || targets.length === 0) return true;
  const firstValue = targets[0].target_value;
  return targets.every(
    (t: any) => Math.abs(Number(t.target_value) - Number(firstValue)) < 0.01,
  );
};

export const formatTarget = (value: number | null, type: string): string => {
  if (value === null || value === undefined) return "N/A";
  const numValue = Number(value);
  switch (type) {
    case "currency":
      return `K${numValue.toLocaleString("en-US", { minimumFractionDigits: 0, maximumFractionDigits: 2 })}`;
    case "percentage":
      return `${numValue}%`;
    case "boolean":
      return numValue ? "Yes" : "No";
    default:
      return numValue.toLocaleString("en-US", {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
      });
  }
};

export const determineAppraisalBehaviour = (behaviour: string): string => {
  if (!behaviour) return "higher_better";
  const lower = behaviour.toLowerCase();
  if (
    lower.includes("lower") ||
    lower.includes("bad") ||
    lower.includes("descending")
  ) {
    return "higher_bad";
  }
  return "higher_better";
};

export const parseTargetType = (targetType: string): string => {
  if (!targetType) return "numeric";
  const lower = targetType.toLowerCase();
  if (lower.includes("currency") || lower.includes("monetary"))
    return "currency";
  if (lower.includes("percentage") || lower.includes("%")) return "percentage";
  if (
    lower.includes("yes") ||
    lower.includes("no") ||
    lower.includes("boolean")
  )
    return "boolean";
  return "numeric";
};

export const parseObjectiveType = (
  objectiveType: string,
): { type: string; absoluteValue: string | null } => {
  if (!objectiveType) return { type: "continuous", absoluteValue: null };
  const lower = objectiveType.toLowerCase();
  if (lower.includes("absolute")) {
    const match = objectiveType.match(/absolute\s*[-–—]?\s*(.+)/i);
    const absoluteValue = match ? match[1].trim() : null;
    return { type: "absolute", absoluteValue };
  }
  return { type: "continuous", absoluteValue: null };
};

export const parseTargetValue = (targetValue: any): number => {
  if (typeof targetValue === "number") return targetValue;
  if (typeof targetValue === "string") {
    const cleanValue = targetValue.replace(/[Kk$,\s]/g, "");
    return parseFloat(cleanValue) || 0;
  }
  return 0;
};

// ────────────────────────────────────────────────
// Excel Processing & Parsing
// ────────────────────────────────────────────────
export const readExcelFile = (file: File): Promise<any[]> => {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = async (e) => {
      try {
        const buffer = e.target?.result as ArrayBuffer;
        const workbook = new ExcelJS.Workbook();
        await workbook.xlsx.load(buffer);

        let worksheet = workbook.getWorksheet("Balanced Scorecard");
        if (!worksheet) worksheet = workbook.worksheets[0];

        const jsonData: any[] = [];
        worksheet.eachRow((row) => {
          const rowData: any[] = [];
          row.eachCell({ includeEmpty: true }, (cell) =>
            rowData.push(cell.value),
          );
          jsonData.push(rowData);
        });

        resolve(jsonData);
      } catch (error) {
        reject(error);
      }
    };
    reader.onerror = () => reject(new Error("Failed to read file"));
    reader.readAsArrayBuffer(file);
  });
};

export interface PerspectiveData {
  perspective_name: string;
  perspective_weight: number;
  goals: any[];
}

// ✅ FIXED VERSION - This was the problematic function
export const extractPerspectiveData = (
  data: any[],
  startRow: number,
  endRow: number,
  perspectiveName: string,
  tenantConfig: any,
): PerspectiveData => {
  const goals: any[] = [];
  let currentGoal: any = null;

  const perspectiveWeight = Number(data[startRow]?.[4]) || 0;

  const matrixTemplate = tenantConfig?.matrixTemplate;
  const requiresThresholds = matrixTemplate?.requires_thresholds ?? false;
  const thresholdConfigs = matrixTemplate?.threshold_configs || [];

  console.log(`Processing perspective: ${perspectiveName}`);
  console.log(`  Rows: ${startRow} to ${endRow}`);
  console.log(`  Matrix requires thresholds: ${requiresThresholds}`);

  for (let i = startRow; i < endRow; i++) {
    const row = data[i];
    if (!row || row.length === 0) continue;

    const goalName = row[6]?.toString().trim();
    const goalWeight = Number(row[8]) || 0;
    const objName = row[10]?.toString().trim();
    const objWeight = Number(row[11]) || 0;

    // Check for placeholder text
    const isPlaceholderGoal =
      goalName &&
      (goalName.toLowerCase().startsWith("goal ") ||
        goalName.toLowerCase().startsWith("sample") ||
        goalName.toLowerCase() === "goals" ||
        goalName.toLowerCase().includes("placeholder"));

    const isPlaceholderObj =
      objName &&
      (objName.toLowerCase().startsWith("objective ") ||
        objName.toLowerCase().startsWith("sample") ||
        objName.toLowerCase() === "objectives" ||
        objName.toLowerCase().includes("placeholder"));

    // Skip placeholder goals entirely
    if (isPlaceholderGoal) {
      console.log(`  Skipping placeholder goal: ${goalName}`);
      continue;
    }

    // ✅ FIXED: Process NEW goal (don't search for existing, just check if name changed)
    if (goalName && goalName.trim() !== "" && !isPlaceholderGoal) {
      // Only create new goal if the name is different from current goal
      if (!currentGoal || currentGoal.description !== goalName) {
        console.log(`  Creating new goal: ${goalName} (${goalWeight}%)`);
        currentGoal = {
          description: goalName,
          weight: goalWeight || 0,
          objectives: [],
        };
        goals.push(currentGoal);
      } else {
        console.log(`  Continuing with goal: ${goalName}`);
      }
    }

    // ✅ Process objective (must have a currentGoal)
    if (currentGoal && objName && objName.trim() !== "" && !isPlaceholderObj) {
      console.log(`    Adding objective: ${objName} (${objWeight}%)`);

      const objectiveTypeRaw = row[15]?.toString().trim() || "continuous";
      const targetType = row[17]?.toString().trim() || "numeric";
      const behaviour = row[19]?.toString().trim() || "higher_better";

      const appraisalBehaviour = determineAppraisalBehaviour(behaviour);
      const parsedTargetType = parseTargetType(targetType);
      const { type: parsedObjectiveType, absoluteValue } =
        parseObjectiveType(objectiveTypeRaw);
      const requiresProof = parsedObjectiveType === "absolute";

      let targets: any[] = [];

      if (requiresThresholds && thresholdConfigs.length > 0) {
        // Multiple thresholds scenario
        const thresholdColumnMap: Record<string, number> = {
          Threshold: 21,
          Standard: 22,
          Stretch: 23,
        };

        targets = thresholdConfigs.map((threshold: any) => {
          const thresholdName = threshold.threshold_name;
          const columnIndex = thresholdColumnMap[thresholdName] || 21;
          const rawValue = row[columnIndex] || 0;
          const parsedValue = parseTargetValue(rawValue);

          console.log(`      Threshold: ${thresholdName} = ${parsedValue}`);

          return {
            threshold_config_id: threshold.id,
            threshold_name: threshold.threshold_name,
            target_value: parsedValue,
          };
        });
      } else {
        // Single target scenario
        const targetValue = row[21] || 0;
        const parsedTargetValue = parseTargetValue(targetValue);

        console.log(`      Single target: ${parsedTargetValue}`);

        targets = [
          {
            threshold_config_id: null,
            threshold_name: "Target",
            target_value: parsedTargetValue,
          },
        ];
      }

      const objective = {
        description: objName,
        objective_type: parsedObjectiveType,
        absolute_value: absoluteValue,
        target_type: parsedTargetType,
        appraisal_behaviour: appraisalBehaviour,
        weight: objWeight,
        requires_proof: requiresProof,
        proof_requirements: absoluteValue || "",
        targets,
      };

      currentGoal.objectives.push(objective);
    }
  }

  const validGoals = goals.filter(
    (g) => g.objectives && g.objectives.length > 0,
  );

  console.log(`Completed perspective ${perspectiveName}:`);
  console.log(`  Total goals: ${validGoals.length}`);
  validGoals.forEach((g, idx) => {
    console.log(
      `  Goal ${idx + 1}: ${g.description} (${g.objectives.length} objectives)`,
    );
  });

  return {
    perspective_name: perspectiveName,
    perspective_weight: perspectiveWeight,
    goals: validGoals,
  };
};

export const processFullScorecardExcel = (
  data: any[],
  tenantConfig: any,
): PerspectiveData[] | null => {
  if (data.length < 14) {
    return null;
  }

  const perspectiveKeywords = [
    "financial",
    "customer",
    "internal",
    "learning",
    "growth",
  ];
  const perspectiveRanges: Array<{
    name: string;
    startRow: number;
    endRow: number;
  }> = [];

  // Find perspective sections
  for (let i = 0; i < data.length; i++) {
    const row = data[i];
    if (!row) continue;

    const perspectiveCell = row[2]?.toString().trim();
    if (perspectiveCell) {
      const matchedKeyword = perspectiveKeywords.find((keyword) =>
        perspectiveCell.toLowerCase().includes(keyword),
      );
      if (matchedKeyword) {
        if (perspectiveRanges.length > 0) {
          perspectiveRanges[perspectiveRanges.length - 1].endRow = i;
        }
        perspectiveRanges.push({
          name: perspectiveCell,
          startRow: i,
          endRow: data.length,
        });
      }
    }
  }

  const filteredRanges = perspectiveRanges.filter(
    (range) => !range.name.toLowerCase().includes("total"),
  );

  const allPerspectives: PerspectiveData[] = [];

  for (const range of filteredRanges) {
    const perspectiveData = extractPerspectiveData(
      data,
      range.startRow,
      range.endRow,
      range.name,
      tenantConfig,
    );
    if (perspectiveData.goals.length > 0) {
      allPerspectives.push(perspectiveData);
    }
  }

  return allPerspectives.length > 0 ? allPerspectives : null;
};

// ────────────────────────────────────────────────
// Validation Helpers
// ────────────────────────────────────────────────
export const validatePerspectiveWeights = (perspectives: any[]): string[] => {
  const errors: string[] = [];

  const totalPerspectiveWeight = perspectives.reduce(
    (sum, p) => sum + Number(p.perspective_weight || 0),
    0,
  );

  if (Math.abs(totalPerspectiveWeight - 100) > 0.01) {
    errors.push(
      `Total perspective weights (${totalPerspectiveWeight.toFixed(2)}%) must equal 100%`,
    );
  }

  perspectives.forEach((perspective) => {
    const perspectiveWeight = Number(perspective.perspective_weight || 0);
    const totalGoalWeight = perspective.goals.reduce(
      (sum: number, g: any) => sum + Number(g.weight || 0),
      0,
    );

    if (Math.abs(totalGoalWeight - perspectiveWeight) > 0.01) {
      errors.push(
        `${perspective.perspective_name}: Goal weights (${totalGoalWeight.toFixed(2)}%) must equal perspective weight (${perspectiveWeight}%)`,
      );
    }

    perspective.goals.forEach((goal: any) => {
      const goalWeight = Number(goal.weight || 0);
      const totalObjWeight = goal.objectives.reduce(
        (sum: number, obj: any) => sum + Number(obj.weight || 0),
        0,
      );

      if (Math.abs(totalObjWeight - goalWeight) > 0.01) {
        errors.push(
          `Goal "${goal.description}": Objective weights (${totalObjWeight.toFixed(2)}%) must equal goal weight (${goalWeight}%)`,
        );
      }
    });
  });

  return errors;
};
