<?php

namespace App\Http\Controllers;

use App\Models\ScheduleTask;
use Cron\CronExpression;
use Illuminate\Http\Request;

class ScheduleTaskController extends Controller
{
    public function index(Request $request) {
        try {
            $user_id = $request->user_id;
            $data = ScheduleTask::where('user_id', $user_id)->get();
            return response()->json([
                'success' => true,
                'message' => "Schedule retrieve successful",
                'data' => $data
            ]);
        } catch (\Throwable $th) {
            \Log::info("Failed to get user Schedule Task : " . $th->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Failed to get user schedule"
            ]);
        }
    }

    public function show($id, Request $request) {
        try {
            $user_id = $request->user_id;
            $data = ScheduleTask::where('id', $id)->first();
            if ($data->user_id != $user_id) {
                return response()->json([
                    'success' => false,
                    'message' => "You don't have permission to view this",
                    'data' => null
                ]);
            } else {
                return response()->json([
                    'success' => true,
                    'message' => "Schedule retrieve successful",
                    'data' => $data
                ]);
            }
        } catch (\Throwable $th) {
            \Log::info("Failed to get Schedule Task : " . $th->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Failed to get schedule"
            ]);
        }
    }

    public function update($id, Request $request) {
        try {
            $request->validate([
                'user_id' => 'required|integer',
                'schedule' => 'required|string',
            ]);
    
            $now = now();
            $user_id = $request->user_id;
            $command = "schedule-analysis";
            $cronExpression = $request->schedule;
            $cron = CronExpression::factory($cronExpression);
            $nextRunDate = $cron->getNextRunDate($now)->format('Y-m-d H:i:s');
    
            ScheduleTask::where('id', $id)->update([
                'user_id' => $user_id,
                'command' => $command,
                'parameter' => $request->except(['user_id', 'schedule']),
                'cron_expression' => $cronExpression,
                'execute_at' => $nextRunDate
            ]);
    
            return response()->json([
                'success' => true,
                'message' => "Schedule updated successful"
            ]);
        } catch (\Throwable $th) {
            \Log::info("Failed to update Schedule Task : " . $th->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Failed to update schedule"
            ]);
        }
    }

    public function store(Request $request) {
        try {
            $request->validate([
                'user_id' => 'required|integer',
                'schedule' => 'required|string',
            ]);
    
            $now = now();
            $user_id = $request->user_id;
            $command = "schedule-analysis";
            $cronExpression = $request->schedule;
            $cron = CronExpression::factory($cronExpression);
            $nextRunDate = $cron->getNextRunDate($now)->format('Y-m-d H:i:s');
    
            ScheduleTask::create([
                'user_id' => $user_id,
                'command' => $command,
                'parameter' => $request->except(['user_id', 'schedule']),
                'cron_expression' => $cronExpression,
                'execute_at' => $nextRunDate
            ]);
    
            return response()->json([
                'success' => true,
                'message' => "Schedule created successful"
            ]);
        } catch (\Throwable $th) {
            \Log::info("Failed to create Schedule Task : " . $th->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Failed to create schedule"
            ]);
        }
    }

    public function destroy($id, Request $request) {
        try {
            $user_id = $request->user_id;
            $schedule = ScheduleTask::find($id);
            if ($schedule->user_id != $user_id) {
                return response()->json([
                    'success' => false,
                    'message' => "You don't have permission to do this action",
                    'request' => $request->all(),
                    'id' => $id
                ]);
            } else {
                $schedule->delete();
                return response()->json([
                    'success' => true,
                    'message' => "Schedule deleted successful"
                ]);
            }
        } catch (\Throwable $th) {
            \Log::info("Failed to delete Schedule Task : " . $th->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Failed to delete schedule"
            ]);
        }

    }
}
