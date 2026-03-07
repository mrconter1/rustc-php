// stdout: 5
// stdout: 10
// stdout: 0
// stdout: 7
// stdout: 14
// exit: 0

fn add_one(n: i32) -> i32 {
    n + 1
}

fn maybe_double(opt: Option<i32>) -> i32 {
    match opt {
        Option::<i32>::Some(x) => x * 2,
        Option::<i32>::None => 0,
    }
}

fn result_add(r: Result<i32, i32>) -> i32 {
    match r {
        Result::<i32, i32>::Ok(x) => x + 1,
        Result::<i32, i32>::Err(e) => e,
    }
}

fn main() {
    let a: Option<i32> = Option::<i32>::Some(4);
    let v = add_one(maybe_double(a) / 2);
    println!("{}", v);
    let b: Option<i32> = Option::<i32>::Some(5);
    println!("{}", maybe_double(b));
    let c: Option<i32> = Option::<i32>::None;
    println!("{}", maybe_double(c));
    let d: Result<i32, i32> = Result::<i32, i32>::Ok(6);
    println!("{}", result_add(d));
    let e: Result<i32, i32> = Result::<i32, i32>::Ok(13);
    println!("{}", result_add(e));
    exit(0);
}
